<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Returns every user the caller may legitimately assign to one of their
 * tasks: themselves plus the members of any project they belong to. This
 * matches the ValidAssignees rule on Task — feeding the frontend exactly
 * the candidate set the validator will accept.
 *
 * The response is intentionally a flat User[] (not paginated) — the set is
 * small (project teammates only) and the picker needs the whole list to
 * filter against typeahead input.
 */
class AssignableUsersController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SerializerInterface $serializer,
    ) {
    }

    #[Route('/me/assignable-users', name: 'me_assignable_users', methods: ['GET'])]
    public function __invoke(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $byId = [(string) $user->getId() => $user];
        $projects = $this->em->getRepository(Project::class)
            ->createQueryBuilder('p')
            ->leftJoin('p.members', 'pm')
            ->addSelect('pm')
            ->where(':self MEMBER OF p.members')
            ->setParameter('self', $user)
            ->getQuery()
            ->getResult();
        foreach ($projects as $project) {
            foreach ($project->getMembers() as $member) {
                $byId[(string) $member->getId()] = $member;
            }
        }

        $users = array_values($byId);
        $json = $this->serializer->serialize(
            $users,
            'jsonld',
            ['groups' => ['user:read']],
        );
        $members = json_decode($json, true) ?? [];

        // Wrap in a hydra-shaped collection so the frontend can consume this
        // the same way it consumes `/tasks` and `/projects`.
        return new JsonResponse([
            '@context' => '/contexts/User',
            '@id' => '/me/assignable-users',
            '@type' => 'Collection',
            'totalItems' => count($members),
            'member' => $members,
        ]);
    }
}
