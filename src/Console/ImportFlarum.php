<?php

namespace Waterhole\Import\Console;

use DateTime;
use DOMDocument;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Waterhole\Formatter\FormatMentions;
use Waterhole\Import\Console\Concerns\ImportsFromDatabase;
use Waterhole\Models\Channel;
use Waterhole\Models\Comment;
use Waterhole\Models\Group;
use Waterhole\Models\Model;
use Waterhole\Models\Post;
use Waterhole\Models\PostUser;
use Waterhole\Models\ReactionSet;
use Waterhole\Models\User;

class ImportFlarum extends Command
{
    use ImportsFromDatabase;

    const GROUP_MAP = [
        1 => Group::ADMIN_ID,
        2 => Group::GUEST_ID,
        3 => Group::MEMBER_ID,
    ];

    protected $name = 'waterhole:import:flarum';

    protected $description = 'Import data from Flarum into Waterhole';

    private function import(ConnectionInterface $connection): void
    {
        $this->seedReactions();

        $this->importUsers($connection);
        $this->importGroups($connection);
        $this->importTagsAsChannels($connection);
        $this->importDiscussionsAsPosts($connection);
        $this->importPostsAsComments($connection);
    }

    private function importUsers(ConnectionInterface $connection): void
    {
        $users = $connection->table('users')->orderBy('id');

        $this->importFromDatabase('users', $users, function ($row) {
            if (!$row->username) {
                return;
            }

            User::create([
                'id' => $row->id,
                'name' => $row->username,
                'email' => $row->email,
                'email_verified_at' => $row->is_email_confirmed ? now() : null,
                'bio' => $row->bio,
                'avatar' => $row->avatar_url,
                'created_at' => new DateTime($row->joined_at),
                'last_seen_at' => new DateTime($row->last_seen_at),
                'notifications_read_at' => new DateTime($row->read_notifications_at),
                'suspended_until' => $row->suspended_until
                    ? min((new DateTime($row->suspended_until))->getTimestamp(), 2147483647)
                    : null,
            ]);
        });
    }

    private function importGroups(ConnectionInterface $connection): void
    {
        $groups = $connection->table('groups')->orderBy('id');

        $this->importFromDatabase('groups', $groups, function ($row) {
            Group::create([
                'id' => static::GROUP_MAP[$row->id] ?? $row->id,
                'name' => $row->name_singular,
                'color' => ltrim($row->color, '#') ?: null,
                'is_public' =>
                    !isset(static::GROUP_MAP[$row->id]) &&
                    (!isset($row->is_hidden) || !$row->is_hidden),
            ]);
        });

        $groupAssociations = $connection->table('group_user')->orderBy('group_id');

        $this->importFromDatabase('group associations', $groupAssociations, function ($row) {
            DB::table('group_user')->insert([
                'group_id' => static::GROUP_MAP[$row->group_id] ?? $row->group_id,
                'user_id' => $row->user_id,
            ]);
        });
    }

    private function importTagsAsChannels(ConnectionInterface $connection): void
    {
        $tags = $connection
            ->table('tags')
            ->whereNotNull('position')
            ->whereNull('parent_id')
            ->orderBy('position');

        $this->importFromDatabase('channels', $tags, function ($row) {
            $channel = Channel::create([
                'id' => $row->id,
                'name' => $row->name,
                'slug' => $row->slug,
                'description' => $row->description,
            ]);

            $channel->structure->update(['is_listed' => true]);
        });
    }

    private function seedReactions(): void
    {
        ReactionSet::create([
            'name' => 'Likes',
            'is_default_posts' => true,
            'is_default_comments' => true,
        ])
            ->reactionTypes()
            ->create(['name' => 'Like', 'icon' => 'emoji:👍', 'score' => 1, 'position' => 0]);
    }

    private function importDiscussionsAsPosts(ConnectionInterface $connection): void
    {
        $channelIds = Channel::query()->pluck('id');

        $discussions = $connection
            ->table('discussions')
            ->leftJoin('posts', 'posts.id', '=', 'first_post_id')
            ->select('discussions.*')
            ->selectSub(function ($query) use ($channelIds) {
                $query
                    ->selectRaw('min(tag_id)')
                    ->from('discussion_tag')
                    ->whereColumn('discussion_id', 'discussions.id')
                    ->whereIn('tag_id', $channelIds);
            }, 'tag_id')
            ->addSelect('posts.content')
            ->selectSub(function ($query) {
                $query
                    ->selectRaw('group_concat(user_id)')
                    ->from('post_likes')
                    ->whereColumn('post_id', 'first_post_id');
            }, 'liked_by')
            ->whereNull('discussions.hidden_at')
            ->where('discussions.is_private', false)
            ->orderBy('discussions.id');

        $this->importFromDatabase('posts', $discussions, function ($row) use ($channelIds) {
            /** @var Post $post */
            $post = Post::withoutEvents(
                fn() => Post::create([
                    'id' => $row->id,
                    'channel_id' => $row->tag_id ?: $channelIds[0],
                    'user_id' => $row->user_id,
                    'title' => $row->title,
                    'slug' => $row->slug,
                    'parsed_body' => $this->reformat($row->content ?: ''),
                    'created_at' => $row->created_at,
                    'last_activity_at' => $row->last_posted_at ?: $row->created_at,
                    'comment_count' => max(0, $row->comment_count - 1),
                    'is_locked' => $row->is_locked,
                ]),
            );

            $this->createReactions($post, array_filter(explode(',', $row->liked_by)));
            $this->createMentions($post);
        });

        $discussionUser = $connection
            ->table('discussion_user')
            ->select('*')
            ->orderBy('discussion_id');

        $this->importFromDatabase('discussion user records', $discussionUser, function ($row) {
            PostUser::withoutEvents(
                fn() => PostUser::create([
                    'post_id' => $row->discussion_id,
                    'user_id' => $row->user_id,
                    'last_read_at' => new DateTime($row->last_read_at),
                    'notifications' => $row->subscription,
                ]),
            );
        });
    }

    private function importPostsAsComments(ConnectionInterface $connection): void
    {
        $posts = $connection
            ->table('posts')
            ->join('discussions', 'discussions.id', '=', 'discussion_id')
            ->where('posts.number', '!=', 1)
            ->where('type', 'comment')
            ->whereNull('posts.hidden_at')
            ->where('posts.is_private', false)
            ->select('posts.*')
            ->selectSub(function ($query) {
                $query
                    ->selectRaw('min(mentions_post_id)')
                    ->from('post_mentions_post')
                    ->join('posts as m', function ($join) {
                        $join->on('mentions_post_id', '=', 'm.id')->where('m.number', '!=', 1);
                    })
                    ->whereColumn('post_id', 'posts.id');
            }, 'mentions_post_id')
            ->selectSub(function ($query) {
                $query
                    ->selectRaw('count(*)')
                    ->from('post_mentions_post')
                    ->whereColumn('mentions_post_id', 'posts.id')
                    ->limit(1);
            }, 'reply_count')
            ->selectSub(function ($query) {
                $query
                    ->selectRaw('group_concat(user_id)')
                    ->from('post_likes')
                    ->whereColumn('post_id', 'posts.id');
            }, 'liked_by')
            ->orderBy('posts.id');

        $this->importFromDatabase('comments', $posts, function ($row) {
            /** @var Comment $comment */
            $comment = Comment::withoutEvents(
                fn() => Comment::create([
                    'id' => $row->id,
                    'post_id' => $row->discussion_id,
                    'parent_id' => $row->mentions_post_id,
                    'user_id' => $row->user_id,
                    'parsed_body' => $this->reformat($row->content ?: ''),
                    'created_at' => new DateTime($row->created_at),
                    'edited_at' => new DateTime($row->edited_at),
                    'reply_count' => $row->reply_count ?: 0,
                ]),
            );

            $this->createReactions($comment, array_filter(explode(',', $row->liked_by)));
            $this->createMentions($comment);
        });
    }

    private function reformat(string $xml): string
    {
        if (!$xml) {
            return '';
        }

        $dom = new DOMDocument();
        $dom->loadXML($xml);

        // Convert user mentions into new format
        // Flarum format: <USERMENTION id="1" username="Toby">@Toby</USERMENTION>
        $elements = $dom->getElementsByTagName('USERMENTION');
        for ($i = $elements->length - 1; $i > -1; $i--) {
            $el = $elements->item($i);
            $new = $dom->createElement('MENTION', $el->textContent);
            $new->setAttribute('id', $el->getAttribute('id'));
            $new->setAttribute('name', $el->getAttribute('username'));
            $el->parentNode->replaceChild($new, $el);
        }

        // Remove post mentions
        // Flarum format: <POSTMENTION discussionid="1" displayname="Toby" id="123" number="1" username="Toby">@Toby#123</POSTMENTION>
        $elements = $dom->getElementsByTagName('POSTMENTION');
        for ($i = $elements->length - 1; $i > -1; $i--) {
            $el = $elements->item($i);
            $el->parentNode->removeChild($el);
        }

        return $dom->saveXML($dom->documentElement) ?: '';
    }

    private function createReactions(Model $model, array $userIds): void
    {
        $model->reactions()->delete();
        $model
            ->reactions()
            ->createMany(
                array_map(fn($userId) => ['reaction_type_id' => 1, 'user_id' => $userId], $userIds),
            );

        $model->recalculateScore()->save();
    }

    private function createMentions(Model $model): void
    {
        $model->mentions()->sync(FormatMentions::getMentionedUsers($model->parsed_body));
    }
}
