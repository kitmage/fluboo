<?php

namespace FluentBookingPro\App\Http\Controllers;

use FluentBooking\App\Http\Controllers\Controller;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\Meta;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\Framework\Http\Request\Request;
use FluentBooking\App\Services\Helper;

class TeamController extends Controller
{
    public function getTeamMembers()
    {
        $calendars = Calendar::where('type', '!=', 'team')->get();
        $calendarUserIds = $calendars->pluck('user_id')->filter()->unique()->toArray();

        $otherReadonlyQuery = Meta::where('object_type', 'user_meta')->where('key', '_access_permissions');
        if ($calendarUserIds) {
            $otherReadonlyQuery = $otherReadonlyQuery->whereNotIn('object_id', $calendarUserIds);
        }
        $otherReadonlyMetas = $otherReadonlyQuery->get();

        // Batch-load all users in one query instead of N get_user_by() calls
        $allUserIds = array_unique(array_merge(
            $calendarUserIds,
            $otherReadonlyMetas->pluck('object_id')->filter()->toArray()
        ));

        $usersMap = [];
        if ($allUserIds) {
            foreach (get_users(['include' => $allUserIds, 'number' => count($allUserIds)]) as $user) {
                $usersMap[$user->ID] = $user;
            }
        }

        $teamMembers = [];

        foreach ($calendars as $calendar) {
            $user = $usersMap[$calendar->user_id] ?? null;
            if (!$user) {
                continue;
            }

            $name    = Helper::getDisplayNameFromUser($user);
            $isAdmin = user_can($user, 'manage_options');

            $data = [
                'id'               => $calendar->user_id,
                'name'             => $name,
                'email'            => $user->user_email,
                'avatar'           => $calendar->getAuthorPhoto(),
                'is_admin'         => $isAdmin,
                'is_calendar_user' => true,
            ];

            if (!$isAdmin) {
                $permissions = PermissionManager::getMetaPermissions($user->ID);
                $data['permissions'] = $permissions ?: ['manage_own_calendar'];
            }

            $teamMembers[$user->ID] = $data;
        }

        foreach ($otherReadonlyMetas as $meta) {
            $user = $usersMap[$meta->object_id] ?? null;
            if (!$user) {
                $meta->delete();
                continue;
            }

            if (user_can($user, 'manage_options') && !$meta->value) {
                $meta->delete();
                continue;
            }

            $name = Helper::getDisplayNameFromUser($user);

            $teamMembers[$user->ID] = [
                'id'          => (string) $meta->object_id,
                'name'        => $name,
                'email'       => $user->user_email,
                'avatar'      => Helper::fluentBookingUserAvatar($user->id, $user->id),
                'is_admin'    => user_can($user, 'manage_options'),
                'permissions' => $meta->value,
            ];
        }

        return [
            'members'         => array_values($teamMembers),
            'permission_sets' => PermissionManager::allPermissionSets(),
        ];
    }

    public function updateMemberPermission(Request $request)
    {
        $this->validate($request->all(), [
            'user_id'     => 'required|numeric',
            'permissions' => 'required|array'
        ]);

        $user = get_user_by('ID', $request->get('user_id'));

        if (user_can($user, 'manage_options')) {
            return $this->sendError([
                'success' => false,
                'message' => __('This is an admin user. You can not change permissions', 'fluent-booking-pro')
            ]);
        }

        $permissions = $request->get('permissions');

        $permissionSets = PermissionManager::allPermissionSets();

        $validPermissions = array_intersect($permissions, array_keys($permissionSets));

        if (!in_array('manage_own_calendar', $validPermissions)) {
            $validPermissions[] = 'manage_own_calendar';
        }
        $validPermissions = array_unique($validPermissions);

        $meta = Meta::where('object_type', 'user_meta')
            ->where('object_id', $user->ID)
            ->where('key', '_access_permissions')
            ->first();

        $message = __('Access Permissions has been updated successfully', 'fluent-booking-pro');

        if ($meta) {
            $meta->value = $validPermissions;
            $meta->save();
        } else {
            Meta::create([
                'object_type' => 'user_meta',
                'object_id'   => $user->ID,
                'key'         => '_access_permissions',
                'value'       => $validPermissions
            ]);
            $message = __('New member has been added with the selected access permissions', 'fluent-booking-pro');
        }

        return [
            'message' => $message
        ];
    }

    public function deleteMember(Request $request, $id)
    {
        if (!$id) {
            return;
        }

        Meta::where('object_type', 'user_meta')
            ->where('object_id', $id)
            ->where('key', '_access_permissions')
            ->delete();

        return [
            'message' => __('Member has been deleted successful', 'fluent-booking-pro')
        ];

    }
}
