<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Determine whether the user can view the team's projects.
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can view the project.
     */
    public function view(User $user, Project $project): bool
    {
        return $user->belongsToTeam($project->team);
    }

    /**
     * Determine whether the user can create a project for the team.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::CreateProject);
    }

    /**
     * Determine whether the user can update the project.
     */
    public function update(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team, TeamPermission::UpdateProject);
    }

    /**
     * Determine whether the user can see the plaintext of a secret.
     */
    public function viewSecrets(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team, TeamPermission::ViewSecretValue);
    }

    /**
     * Determine whether the user can add or change the project's variables.
     */
    public function manageVariables(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team, TeamPermission::ManageVariable);
    }

    /**
     * Determine whether the user can publish or roll back releases.
     */
    public function publishReleases(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team, TeamPermission::PublishRelease);
    }

    /**
     * Determine whether the user can manage the project's deploy tokens.
     */
    public function manageDeployTokens(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team, TeamPermission::ManageDeployToken);
    }

    /**
     * Determine whether the user can delete the project.
     */
    public function delete(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team, TeamPermission::DeleteProject);
    }
}
