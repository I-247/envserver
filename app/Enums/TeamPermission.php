<?php

namespace App\Enums;

enum TeamPermission: string
{
    case UpdateTeam = 'team:update';
    case DeleteTeam = 'team:delete';

    case AddMember = 'member:add';
    case UpdateMember = 'member:update';
    case RemoveMember = 'member:remove';

    case CreateInvitation = 'invitation:create';
    case CancelInvitation = 'invitation:cancel';

    case CreateProject = 'project:create';
    case UpdateProject = 'project:update';
    case DeleteProject = 'project:delete';

    case ManageVariable = 'variable:manage';
    case ViewSecretValue = 'secret:view';

    case PublishRelease = 'release:publish';

    case ManageDeployToken = 'deploy-token:manage';
}
