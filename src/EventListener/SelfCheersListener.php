<?php

namespace App\EventListener;

use App\Entity\Reactable;
use App\Services\Reactions;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Whatever is made that can be cheered, a decklist, a library or a comment, starts with its
 * author's own Cheers (Ketura, 2026-11-11, as reddit does), however it was made: published,
 * imported, copied, posted
 */
#[AsDoctrineListener(event: Events::postPersist)]
class SelfCheersListener
{
    public function __construct(private readonly Reactions $reactions)
    {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof Reactable) {
            $this->reactions->seed($entity);
        }
    }
}
