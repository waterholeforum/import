<?php

namespace Waterhole\Import\Console;

use DOMDocument;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Waterhole\Import\Console\Concerns\ImportsFromDatabase;
use Waterhole\Models\Channel;
use Waterhole\Models\Comment;
use Waterhole\Models\Group;
use Waterhole\Models\Post;
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

    private function import(ConnectionInterface $connection)
    {
        $this->importUsers($connection);
        $this->importGroups($connection);
        $this->importTagsAsChannels($connection);
        $this->importDiscussionsAsPosts($connection);
        $this->importPostsAsComments($connection);

        // TODO: import subscriptions, read state, mentions
    }

    private function importUsers(ConnectionInterface $connection)
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
                'locale' => $row->locale,
                'bio' => $row->bio,
                'avatar' => $row->avatar_url,
                'created_at' => $row->joined_at,
                'last_seen_at' => $row->last_seen_at,
                'notifications_read_at' => $row->read_notifications_at,
                // 'suspend_until' => $row->suspended_until, // TODO: can be invalid
            ]);
        });
    }

    private function importGroups(ConnectionInterface $connection)
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

    private function importTagsAsChannels(ConnectionInterface $connection)
    {
        $tags = $connection
            ->table('tags')
            ->whereNotNull('position')
            ->whereNull('parent_id')
            ->orderBy('position');

        $this->importFromDatabase('channels', $tags, function ($row) {
            Channel::create([
                'id' => $row->id,
                'name' => $row->name,
                'slug' => $row->slug,
                'description' => $row->description,
            ]);
        });
    }

    private function importDiscussionsAsPosts(ConnectionInterface $connection)
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
            ->whereNull('hidden_at')
            ->where('is_private', false)
            ->orderBy('discussions.id');

        $this->importFromDatabase('posts', $discussions, function ($row) use ($channelIds) {
            $likedBy = array_filter(explode(',', $row->liked_by));

            $post = Post::withoutEvents(
                fn() => Post::create([
                    'id' => $row->id,
                    'channel_id' => $row->tag_id ?: $channelIds[0],
                    'user_id' => $row->user_id,
                    'title' => $row->title,
                    'slug' => $row->slug,
                    'parsed_body' => $this->reformat($row->content ?: ''),
                    'created_at' => $row->created_at, // TODO: can fail (timezones?)
                    'last_activity_at' => $row->last_posted_at,
                    'comment_count' => $row->comment_count - 1,
                    'score' => count($likedBy),
                    'is_locked' => $row->is_locked,
                ]),
            );

            $post->likedBy()->sync($likedBy);
        });
    }

    private function importPostsAsComments(ConnectionInterface $connection)
    {
        $posts = $connection
            ->table('posts')
            ->join('discussions', 'discussions.id', '=', 'discussion_id')
            ->where('posts.number', '!=', 1)
            ->where('type', 'comment')
            ->whereNull('hidden_at')
            ->where('is_private', false)
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
                    ->whereColumn('mentions_post_id', 'posts.id');
            }, 'reply_count')
            ->selectSub(function ($query) {
                $query
                    ->selectRaw('group_concat(user_id)')
                    ->from('post_likes')
                    ->whereColumn('post_id', 'posts.id');
            }, 'liked_by')
            ->orderBy('posts.id');

        $this->importFromDatabase('comments', $posts, function ($row) {
            $likedBy = array_filter(explode(',', $row->liked_by));

            $comment = Comment::withoutEvents(
                fn() => Comment::create([
                    'id' => $row->id,
                    'post_id' => $row->discussion_id,
                    'parent_id' => $row->mentions_post_id,
                    'user_id' => $row->user_id,
                    'parsed_body' => $this->reformat($row->content ?: ''),
                    'created_at' => $row->created_at,
                    'edited_at' => $row->edited_at,
                    'reply_count' => $row->reply_count,
                    'score' => count($likedBy),
                ]),
            );

            $comment->likedBy()->sync($likedBy);
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
        // <USERMENTION id="1" username="Toby">@Toby</USERMENTION>
        $elements = $dom->getElementsByTagName('USERMENTION');
        for ($i = $elements->length - 1; $i > -1; $i--) {
            $el = $elements->item($i);
            $new = $dom->createElement('MENTION', $el->textContent);
            $new->setAttribute('id', $el->getAttribute('id'));
            $new->setAttribute('name', $el->getAttribute('username'));
            $el->parentNode->replaceChild($new, $el);
        }

        // Remove post mentions
        // <POSTMENTION discussionid="1" displayname="Toby" id="123" number="1" username="Toby">@Toby#123</POSTMENTION>
        $elements = $dom->getElementsByTagName('POSTMENTION');
        for ($i = $elements->length - 1; $i > -1; $i--) {
            $el = $elements->item($i);
            $el->parentNode->removeChild($el);
        }

        return $dom->saveXML($dom->documentElement) ?: '';
    }
}
