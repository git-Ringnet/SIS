<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\CenterStore;
use App\Support\OwnerGuard;
use App\Support\PagePermissions;
use App\Support\TeacherName;
use App\Support\TeacherRates;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class StaffApiController extends Controller
{
    /** Vai trò có toàn quyền như chủ trung tâm (chỉ chủ trung tâm được gán). */
    private const OWNER_ROLE = 'Chủ trung tâm';

    /** Các vai trò còn lại (cùng với Chủ trung tâm là 4 vai trò). */
    private const ROLES = ['Giáo viên', 'Giáo vụ', 'Quản lý'];

    /** Tên vai trò cũ → tên mới (dữ liệu tạo trước khi đổi tên vai trò). */
    private const RENAMED_ROLES = ['Quản lý / Lễ tân' => 'Quản lý'];

    /**
     * Trang bị chặn mặc định theo vai trò khi tạo mới / đổi vai trò.
     */
    private function defaultBlockedPages(string $role): array
    {
        return PagePermissions::defaultBlockedPages($role);
    }

    /**
     * Không cho đổi vai trò / xoá chủ trung tâm gốc (tài khoản đầu tiên) và chính mình, tránh tự khoá quyền.
     */
    private function isProtectedMember(array $member, ?User $actor): bool
    {
        return $member['email'] === User::orderBy('id')->value('email')
            || $member['email'] === $actor?->email;
    }

    /**
     * Gán vai trò cho thành viên, đồng bộ cờ chủ trung tâm và quyền mặc định.
     */
    private function applyRole(array $member, string $role): array
    {
        $isOwner = $role === self::OWNER_ROLE;

        return [
            ...$member,
            'role' => $role,
            'is_owner' => $isOwner,
            'permissions' => $isOwner ? 'full' : 'restricted',
            'blocked_pages' => $this->defaultBlockedPages($role),
        ];
    }

    private function getStaffDataPath(): string
    {
        return 'staff_data.json';
    }

    private function loadStaffData(): array
    {
        $path = $this->getStaffDataPath();
        if (Storage::exists($path)) {
            $data = json_decode(Storage::get($path), true);
            if (is_array($data)) {
                // Danh sách vai trò luôn theo code; vai trò cũ của thành viên đổi sang tên mới
                $data['roles'] = self::ROLES;
                $data['members'] = array_map(
                    fn ($m) => [...$m, 'role' => self::RENAMED_ROLES[$m['role'] ?? ''] ?? ($m['role'] ?? '')],
                    $data['members'] ?? [],
                );

                return $data;
            }
        }

        $owner = User::orderBy('id')->first();

        return [
            'members' => $owner ? [[
                'id' => 1,
                'avatar' => '',
                'name' => $owner->name,
                'email' => $owner->email,
                'role' => 'Chủ trung tâm',
                'permissions' => 'full',
                'blocked_pages' => [],
                'center' => 'Tất cả',
                'joined_at' => $owner->created_at?->format('d/m/Y') ?? Carbon::now()->format('d/m/Y'),
                'is_owner' => true,
            ]] : [],
            'centers' => ['Tất cả trung tâm', ...CenterStore::names()],
            'roles' => self::ROLES,
        ];
    }

    private function saveStaffData(array $data): void
    {
        Storage::put($this->getStaffDataPath(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function index(Request $request): JsonResponse
    {
        $data = $this->loadStaffData();
        $data['owner_role'] = self::OWNER_ROLE;
        // Lương ca của giáo viên / giáo vụ: chỉ chủ trung tâm / quản lý xem được
        $seesPay = PagePermissions::seesAllPay($request->user());
        $data['members'] = array_map(
            fn ($m) => [...$m, 'is_protected' => $this->isProtectedMember($m, $request->user()),
                ...($seesPay && TeacherName::teaches($m['role'] ?? null) ? ['pay' => $this->payOf($m['name'] ?? '')] : [])],
            $data['members'] ?? [],
        );
        $data['sees_pay'] = $seesPay;
        // Giáo viên chỉ có tên trong lịch học (chưa tạo tài khoản ở Nhân sự) vẫn cần đặt lương ca
        $data['unlinked_teachers'] = $seesPay ? array_values(array_map(
            fn ($name) => ['name' => $name, 'pay' => $this->payOf($name)],
            array_filter(TeacherName::all(), fn ($name) => ! collect($data['members'])->contains(fn ($m) => TeacherName::same($m['name'] ?? '', $name))),
        )) : [];
        // Danh sách trung tâm luôn lấy theo màn Trung tâm (bản lưu trong file nhân sự có thể đã cũ)
        $data['centers'] = ['Tất cả trung tâm', ...CenterStore::names()];

        return response()->json($data);
    }

    /**
     * Lương mỗi ca (nhóm / 1:1) đang đặt cho giáo viên / giáo vụ (đơn giá ca dạy, xem TeacherRates).
     */
    private function payOf(string $name): array
    {
        return TeacherRates::forTeacher($name);
    }

    private const PAY_RULES = [
        'pay_group' => 'nullable|numeric|min:0',
        'pay_one_on_one' => 'nullable|numeric|min:0',
    ];

    public function createInvite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'required|email',
            'password' => 'nullable|string|min:6',
            'role' => ['required', 'string', Rule::in([...self::ROLES, self::OWNER_ROLE])],
            'center' => 'required|string',
            ...self::PAY_RULES,
        ]);

        if ($validated['role'] === self::OWNER_ROLE && ! OwnerGuard::isOwner($request->user())) {
            return response()->json(['message' => 'Chỉ chủ trung tâm mới được tạo tài khoản có quyền chủ trung tâm.'], 403);
        }

        if (User::where('email', $validated['email'])->exists()) {
            return response()->json([
                'message' => 'Email này đã có tài khoản đăng nhập trong hệ thống.',
            ], 422);
        }

        $data = $this->loadStaffData();
        $staffName = ! empty($validated['name']) ? $validated['name'] : explode('@', $validated['email'])[0];
        $password = ! empty($validated['password']) ? $validated['password'] : '123456';

        // Tạo tài khoản đăng nhập trong database để nhân sự có thể đăng nhập ngay
        User::create([
            'email' => $validated['email'],
            'name' => $staffName,
            'password' => Hash::make($password),
        ]);

        // Thêm vào danh sách thành viên
        $newMember = $this->applyRole([
            'id' => time(),
            'avatar' => '',
            'name' => $staffName,
            'email' => $validated['email'],
            'center' => $validated['center'] === 'Tất cả trung tâm' ? 'Tất cả' : $validated['center'],
            'joined_at' => Carbon::now()->format('d/m/Y'),
        ], $validated['role']);
        $data['members'][] = $newMember;

        $this->saveStaffData($data);

        // Lương ca đặt ngay lúc tạo giáo viên / giáo vụ (chỉ chủ trung tâm / quản lý)
        if (TeacherName::teaches($newMember['role']) && PagePermissions::seesAllPay($request->user())
            && (isset($validated['pay_group']) || isset($validated['pay_one_on_one']))) {
            TeacherRates::set($staffName, $this->money($validated['pay_group'] ?? null), $this->money($validated['pay_one_on_one'] ?? null));
        }

        ActivityLogger::log('created', 'staff', $staffName, $newMember['id'], ActivityLogger::diff([], [
            'name' => $staffName,
            'email' => $newMember['email'],
            'role' => $newMember['role'],
            'center' => $newMember['center'],
        ]));

        return response()->json([
            'message' => 'Đã tạo tài khoản nhân sự thành công! Nhân viên có thể đăng nhập ngay với mật khẩu: '.$password,
            'data' => $data,
        ], 201);
    }

    private function money(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * Sửa lương mỗi ca của giáo viên / giáo vụ có tài khoản. Áp dụng cho các ca check-in từ bây giờ
     * (ca đã check-in giữ đơn giá đã chốt).
     */
    public function updatePay(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->forbidUnlessSeesPay($request)) {
            return $denied;
        }
        $validated = $request->validate(self::PAY_UPDATE_RULES, self::PAY_UPDATE_MESSAGES);

        $member = collect($this->loadStaffData()['members'] ?? [])->firstWhere('id', $id);
        if (! $member) {
            return response()->json(['message' => 'Không tìm thấy nhân sự.'], 404);
        }
        if (! TeacherName::teaches($member['role'] ?? null) || empty($member['name'])) {
            return response()->json(['message' => 'Chỉ đặt lương ca cho giáo viên / giáo vụ (cần có họ tên).'], 422);
        }

        return $this->savePay($member['name'], $validated, $id);
    }

    /**
     * Sửa lương ca của giáo viên chỉ có tên trong lịch học (chưa có tài khoản ở Nhân sự).
     */
    public function updateTeacherPay(Request $request): JsonResponse
    {
        if ($denied = $this->forbidUnlessSeesPay($request)) {
            return $denied;
        }
        $validated = $request->validate(['name' => 'required|string|max:255', ...self::PAY_UPDATE_RULES], self::PAY_UPDATE_MESSAGES);

        $name = collect(TeacherName::all())->first(fn ($n) => TeacherName::same($n, $validated['name']));
        if (! $name) {
            return response()->json(['message' => 'Không tìm thấy giáo viên này trong lịch học.'], 404);
        }

        return $this->savePay($name, $validated, null);
    }

    private const PAY_UPDATE_RULES = [
        'pay_group' => 'required|numeric|min:0',
        'pay_one_on_one' => 'required|numeric|min:0',
    ];

    private const PAY_UPDATE_MESSAGES = [
        'pay_group.required' => 'Vui lòng nhập lương ca nhóm',
        'pay_one_on_one.required' => 'Vui lòng nhập lương ca 1:1',
    ];

    private function forbidUnlessSeesPay(Request $request): ?JsonResponse
    {
        return PagePermissions::seesAllPay($request->user())
            ? null
            : response()->json(['message' => 'Chỉ chủ trung tâm hoặc quản lý được đặt lương giáo viên / giáo vụ.'], 403);
    }

    private function savePay(string $name, array $validated, ?int $memberId): JsonResponse
    {
        $before = $this->payOf($name);
        TeacherRates::set($name, (float) $validated['pay_group'], (float) $validated['pay_one_on_one']);
        ActivityLogger::log('updated', 'staff', $name, $memberId, ActivityLogger::diff(
            ['note' => 'Lương ca nhóm '.number_format($before['group'], 0, ',', '.').' · 1:1 '.number_format($before['one_on_one'], 0, ',', '.')],
            ['note' => 'Lương ca nhóm '.number_format($validated['pay_group'], 0, ',', '.').' · 1:1 '.number_format($validated['pay_one_on_one'], 0, ',', '.')],
        ));

        return response()->json([
            'message' => "Đã lưu lương ca cho {$name}. Áp dụng cho các ca check-in từ bây giờ.",
            'pay' => $this->payOf($name),
        ]);
    }

    public function updateRole(Request $request, int $id): JsonResponse
    {
        if (! OwnerGuard::isOwner($request->user())) {
            return response()->json(['message' => 'Chỉ chủ trung tâm mới được thay đổi phân quyền nhân sự.'], 403);
        }

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in([...self::ROLES, self::OWNER_ROLE])],
        ]);

        $data = $this->loadStaffData();
        foreach ($data['members'] as &$member) {
            if ($member['id'] !== $id) {
                continue;
            }
            if ($this->isProtectedMember($member, $request->user())) {
                return response()->json(['message' => 'Không thể đổi vai trò của chủ trung tâm gốc hoặc của chính bạn.'], 422);
            }
            ActivityLogger::log('updated', 'staff', $member['name'], $id,
                ActivityLogger::diff(['role' => $member['role']], ['role' => $validated['role']]));
            $member = $this->applyRole($member, $validated['role']);
            break;
        }
        unset($member);

        $this->saveStaffData($data);

        return response()->json([
            'message' => 'Đã cập nhật vai trò nhân sự thành công!',
            'members' => $data['members'],
        ]);
    }

    /**
     * Đổi cơ sở làm việc của thành viên (hiện chỉ dùng để hiển thị / ghi nhận, chưa giới hạn dữ liệu theo cơ sở).
     */
    public function updateCenter(Request $request, int $id): JsonResponse
    {
        if (! OwnerGuard::isOwner($request->user())) {
            return response()->json(['message' => 'Chỉ chủ trung tâm mới được thay đổi thông tin nhân sự.'], 403);
        }

        $validated = $request->validate([
            'center' => ['required', 'string', Rule::in(['Tất cả trung tâm', ...CenterStore::names()])],
        ]);
        $center = $validated['center'] === 'Tất cả trung tâm' ? 'Tất cả' : $validated['center'];

        $data = $this->loadStaffData();
        foreach ($data['members'] as &$member) {
            if ($member['id'] === $id) {
                ActivityLogger::log('updated', 'staff', $member['name'], $id,
                    ActivityLogger::diff(['center' => $member['center'] ?? ''], ['center' => $center]));
                $member['center'] = $center;
                break;
            }
        }
        unset($member);

        $this->saveStaffData($data);

        return response()->json([
            'message' => 'Đã cập nhật cơ sở làm việc!',
            'members' => $data['members'],
        ]);
    }

    /**
     * Chủ trung tâm đặt lại mật khẩu cho nhân sự (khi nhân sự quên mật khẩu).
     */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        if (! OwnerGuard::isOwner($request->user())) {
            return response()->json(['message' => 'Chỉ chủ trung tâm mới được đặt lại mật khẩu nhân sự.'], 403);
        }

        $validated = $request->validate([
            'password' => 'required|string|min:6',
        ], [
            'password.required' => 'Vui lòng nhập mật khẩu mới',
            'password.min' => 'Mật khẩu phải có ít nhất 6 ký tự',
        ]);

        $member = collect($this->loadStaffData()['members'] ?? [])->firstWhere('id', $id);
        if (! $member) {
            return response()->json(['message' => 'Không tìm thấy nhân sự.'], 404);
        }
        // Chủ trung tâm gốc / chính mình đổi mật khẩu ở màn Cài đặt (cần nhập mật khẩu cũ)
        if ($this->isProtectedMember($member, $request->user())) {
            return response()->json(['message' => 'Hãy đổi mật khẩu của tài khoản này ở màn Cài đặt.'], 422);
        }

        $account = User::where('email', $member['email'])->first();
        if (! $account) {
            return response()->json(['message' => 'Nhân sự này chưa có tài khoản đăng nhập.'], 404);
        }

        $account->update(['password' => Hash::make($validated['password'])]);
        // Đăng xuất mọi thiết bị đang dùng mật khẩu cũ
        $account->tokens()->delete();

        ActivityLogger::log('updated', 'staff', $member['name'] ?? $member['email'], $id,
            ActivityLogger::diff([], ['note' => 'Chủ trung tâm đặt lại mật khẩu']));

        return response()->json([
            'message' => "Đã đặt lại mật khẩu cho {$member['email']}. Nhân sự đăng nhập lại bằng mật khẩu mới.",
        ]);
    }

    public function updatePermissions(Request $request, int $id): JsonResponse
    {
        if (! OwnerGuard::isOwner($request->user())) {
            return response()->json(['message' => 'Chỉ chủ trung tâm mới được thay đổi phân quyền nhân sự.'], 403);
        }

        $validated = $request->validate([
            // present thay vì required: mảng rỗng = mở toàn bộ trang
            'blocked_pages' => 'present|array',
            'blocked_pages.*' => 'string',
        ]);

        $data = $this->loadStaffData();
        foreach ($data['members'] as &$member) {
            if ($member['id'] === $id && ! ($member['is_owner'] ?? false)) {
                ActivityLogger::log('updated', 'staff', $member['name'], $id, ActivityLogger::diff(
                    ['blocked_pages' => implode(', ', $member['blocked_pages'] ?? [])],
                    ['blocked_pages' => implode(', ', $validated['blocked_pages'])],
                ));
                $member['blocked_pages'] = $validated['blocked_pages'];
                break;
            }
        }

        $this->saveStaffData($data);

        return response()->json([
            'message' => 'Đã lưu phân quyền trang thành công!',
            'members' => $data['members'],
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! OwnerGuard::isOwner($request->user())) {
            return response()->json(['message' => 'Chỉ chủ trung tâm mới được thay đổi phân quyền nhân sự.'], 403);
        }

        $data = $this->loadStaffData();
        $deleted = collect($data['members'])->first(fn ($m) => $m['id'] === $id);
        if ($deleted && $this->isProtectedMember($deleted, $request->user())) {
            return response()->json(['message' => 'Không thể xoá chủ trung tâm gốc hoặc chính bạn.'], 422);
        }
        if ($deleted) {
            $account = User::where('email', $deleted['email'])->first();
            if ($account) {
                $account->tokens()->delete();
                $account->delete();
            }

            ActivityLogger::log('deleted', 'staff', $deleted['name'], $id, ActivityLogger::diff(
                ['email' => $deleted['email'], 'role' => $deleted['role'], 'center' => $deleted['center']],
                [],
            ));
        }
        $data['members'] = array_values(array_filter($data['members'], fn ($m) => $m['id'] !== $id));

        $this->saveStaffData($data);

        return response()->json([
            'message' => 'Đã xóa thành viên khỏi trung tâm thành công!',
            'members' => $data['members'],
        ]);
    }
}
