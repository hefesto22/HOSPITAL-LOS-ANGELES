<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FusionDePersona;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class FusionDePersonaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:FusionDePersona');
    }

    public function view(AuthUser $authUser, FusionDePersona $fusionDePersona): bool
    {
        return $authUser->can('View:FusionDePersona');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:FusionDePersona');
    }

    public function update(AuthUser $authUser, FusionDePersona $fusionDePersona): bool
    {
        return $authUser->can('Update:FusionDePersona');
    }

    public function delete(AuthUser $authUser, FusionDePersona $fusionDePersona): bool
    {
        return $authUser->can('Delete:FusionDePersona');
    }

    public function restore(AuthUser $authUser, FusionDePersona $fusionDePersona): bool
    {
        return $authUser->can('Restore:FusionDePersona');
    }

    public function forceDelete(AuthUser $authUser, FusionDePersona $fusionDePersona): bool
    {
        return $authUser->can('ForceDelete:FusionDePersona');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:FusionDePersona');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:FusionDePersona');
    }

    public function replicate(AuthUser $authUser, FusionDePersona $fusionDePersona): bool
    {
        return $authUser->can('Replicate:FusionDePersona');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:FusionDePersona');
    }
}
