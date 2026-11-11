<?php

namespace App\Classes;

use App\Entity\Comment;

/**
 * A subject's comments as threads (Ketura, 2026-11-11): each comment with its replies under it,
 * oldest first at every level, as RoyalRoad and Reddit lay them out. A deleted comment stays
 * where it was while a reply under it still stands, and goes once none does. A reply whose
 * comment is gone altogether (a row deleted outright) starts a thread of its own.
 */
final class CommentTree
{
    /**
     * @param Comment[] $comments one subject's
     * @return array<array{comment: Comment, replies: array, count: int}> count: the comments
     *         standing in the thread under it, itself not included
     */
    public static function build(array $comments): array
    {
        usort($comments, fn (Comment $a, Comment $b) => [$a->getDateCreation(), $a->getId()] <=> [$b->getDateCreation(), $b->getId()]);
        $ids = [];
        foreach ($comments as $comment) {
            $ids[$comment->getId()] = true;
        }
        $children = [];
        $roots = [];
        foreach ($comments as $comment) {
            $parent = $comment->getParent()?->getId();
            if ($parent !== null && isset($ids[$parent]) && $parent !== $comment->getId()) {
                $children[$parent][] = $comment;
            } else {
                $roots[] = $comment;
            }
        }
        $grow = function (Comment $comment, array $seen) use (&$grow, $children): ?array {
            if (isset($seen[$comment->getId()])) {
                return null;   // a loop; can't happen through the site, but don't hang on one
            }
            $seen[$comment->getId()] = true;
            $replies = [];
            $count = 0;
            foreach ($children[$comment->getId()] ?? [] as $child) {
                $node = $grow($child, $seen);
                if ($node !== null) {
                    $replies[] = $node;
                    $count += $node['count'] + ($child->isDeleted() ? 0 : 1);
                }
            }
            if ($comment->isDeleted() && $count === 0) {
                return null;
            }
            return ['comment' => $comment, 'replies' => $replies, 'count' => $count];
        };
        return array_values(array_filter(array_map(fn (Comment $c) => $grow($c, []), $roots)));
    }
}
