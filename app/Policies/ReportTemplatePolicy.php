<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReportTemplate;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ReportTemplatePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any_report_template');
    }

    public function view(AuthUser $authUser, ReportTemplate $reportTemplate): bool
    {
        return $authUser->can('view_report_template');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create_report_template');
    }

    public function update(AuthUser $authUser, ReportTemplate $reportTemplate): bool
    {
        return $authUser->can('update_report_template');
    }

    public function delete(AuthUser $authUser, ReportTemplate $reportTemplate): bool
    {
        return $authUser->can('delete_report_template');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any_report_template');
    }

    public function restore(AuthUser $authUser, ReportTemplate $reportTemplate): bool
    {
        return $authUser->can('restore_report_template');
    }

    public function forceDelete(AuthUser $authUser, ReportTemplate $reportTemplate): bool
    {
        return $authUser->can('force_delete_report_template');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any_report_template');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any_report_template');
    }

    public function replicate(AuthUser $authUser, ReportTemplate $reportTemplate): bool
    {
        return $authUser->can('replicate_report_template');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder_report_template');
    }
}
