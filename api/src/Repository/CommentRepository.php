<?php

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Comment>
 */
final class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /**
     * Distinct authors who already commented on the same parent as
     * `$comment`, excluding `$comment` itself — the "reply" recipient set
     * (everyone with skin in the thread). Empty when the comment has no
     * resolvable parent.
     *
     * @return User[]
     */
    public function findPriorAuthors(Comment $comment): array
    {
        // User-rooted + GROUP BY the PK rather than SELECT DISTINCT u:
        // selecting only a joined alias is rejected by the DQL parser, and
        // DISTINCT on the whole row trips over User's json column (no
        // Postgres equality operator). Grouping by the id dedupes cleanly.
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->innerJoin(Comment::class, 'c', Join::ON, 'c.author = u')
            ->where('c.id != :id')
            ->groupBy('u.id')
            ->setParameter('id', $comment->getId());

        if (null !== $comment->getTask()) {
            $qb->andWhere('c.task = :parent')->setParameter('parent', $comment->getTask());
        } elseif (null !== $comment->getPage()) {
            $qb->andWhere('c.page = :parent')->setParameter('parent', $comment->getPage());
        } elseif (null !== $comment->getDiscussion()) {
            $qb->andWhere('c.discussion = :parent')->setParameter('parent', $comment->getDiscussion());
        } elseif (null !== $comment->getFeedback()) {
            $qb->andWhere('c.feedback = :parent')->setParameter('parent', $comment->getFeedback());
        } else {
            return [];
        }

        /** @var User[] $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
