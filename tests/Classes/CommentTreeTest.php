<?php

namespace App\Tests\Classes;

use App\Classes\CommentTree;
use App\Entity\Comment;
use App\Entity\User;
use DateTime;
use PHPUnit\Framework\TestCase;

/** Comments as threads (Ketura, 2026-11-11) */
class CommentTreeTest extends TestCase
{
    private function comment(int $id, string $when, ?Comment $parent = null, bool $deleted = false): Comment
    {
        $c = new Comment();
        $c->setId($id);
        $c->setDateCreation(new DateTime($when));
        $c->setParent($parent);
        if ($deleted) {
            $c->delete(new User());
        }
        return $c;
    }

    /** @return array ids as a nested list, for comparing */
    private function shape(array $nodes): array
    {
        return array_map(fn ($n) => [$n['comment']->getId() => $this->shape($n['replies'])], $nodes);
    }

    public function testRepliesGoUnderWhatTheyAnswerOldestFirst(): void
    {
        $a = $this->comment(1, '2026-11-01 10:00');
        $b = $this->comment(2, '2026-11-01 11:00');
        $a2 = $this->comment(4, '2026-11-01 13:00', $a);
        $a1 = $this->comment(3, '2026-11-01 12:00', $a);
        $a1x = $this->comment(5, '2026-11-01 14:00', $a1);
        $tree = CommentTree::build([$b, $a2, $a1x, $a, $a1]);
        $this->assertSame([[1 => [[3 => [[5 => []]]], [4 => []]]], [2 => []]], $this->shape($tree));
        $this->assertSame(3, $tree[0]['count']);
        $this->assertSame(0, $tree[1]['count']);
    }

    public function testADeletedCommentStaysWhileARepliesStands(): void
    {
        $a = $this->comment(1, '2026-11-01 10:00', null, true);
        $a1 = $this->comment(2, '2026-11-01 11:00', $a);
        $b = $this->comment(3, '2026-11-01 12:00', null, true);
        $b1 = $this->comment(4, '2026-11-01 13:00', $b, true);
        $tree = CommentTree::build([$a, $a1, $b, $b1]);
        // b and its deleted reply go together; a stays over its standing reply
        $this->assertSame([[1 => [[2 => []]]]], $this->shape($tree));
        $this->assertSame(1, $tree[0]['count']);
    }

    public function testAReplyToACommentNotAmongThemStartsAThread(): void
    {
        $elsewhere = $this->comment(9, '2026-11-01 09:00');
        $orphan = $this->comment(1, '2026-11-01 10:00', $elsewhere);
        $this->assertSame([[1 => []]], $this->shape(CommentTree::build([$orphan])));
    }
}
