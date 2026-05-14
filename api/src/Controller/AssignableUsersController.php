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
 * tasks: themselves plus everyone in a space they share with the
 * caller via at least one project (#185). Matches the ValidAssignees
 * rule on Task — feeds the frontend exactly the candidate set the
 * validator will accept.
 *
 * The response is intentionally a flat User[] (not paginated) — the set is
 * small (space teammates only) and the picker needs the whole list to
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
        // The ProjectAccessExtension already scopes "projects the user
        // can see" to those whose space they belong to, so a plain
        // findAll here returns exactly the right set.
        $projects = $this->em->getRepository(Project::class)->findAll();
        foreach ($projects as $project) {
            foreach ($project->getEffectiveMembers() as $id => $member) {
                $byId[$id] = $member;
            }
        }

        $users = array_values($byId);
        $json = $this->serializer->serialize(
            $users,
            'jsonld',
            ['groups' => ['user:read']],
        );
        $decoded = json_decode($json, true);
        $members = is_array($decoded) ? $decoded : [];

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
