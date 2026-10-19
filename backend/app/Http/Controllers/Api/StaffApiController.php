<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\CenterStore;
use App\Support\OwnerGuard;
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

    /**
     * Trang bị chặn mặc định theo vai trò khi tạo mới / đổi vai trò.
     */
    private function defaultBlockedPages(string $role): array
    {
        return match ($role) {
            self::OWNER_ROLE => [],
            'Quản lý / Lễ tân' => ['Cài đặt', 'Dữ liệu'],
            default => ['Doanh thu', 'Hóa đơn & Thu phí', 'Khách hàng tiềm năng', 'Cài đặt', 'Dữ liệu'],
        };
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
            'roles' => [
                'Giáo viên',
                'Quản lý / Lễ tân',
                'Tư vấn viên',
            ],
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
        $data['members'] = array_map(
            fn ($m) => [...$m, 'is_protected' => $this->isProtectedMember($m, $request->user())],
            $data['members'] ?? [],
        );
        // Danh sách trung tâm luôn lấy theo màn Trung tâm (bản lưu trong file nhân sự có thể đã cũ)
        $data['centers'] = ['Tất cả trung tâm', ...CenterStore::names()];

        return response()->json($data);
    }

    public function createInvite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'required|email',
            'password' => 'nullable|string|min:6',
            'role' => 'required|string',
            'center' => 'required|string',
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

    public function updateRole(Request $request, int $id): JsonResponse
    {
        if (! OwnerGuard::isOwner($request->user())) {
            return response()->json(['message' => 'Chỉ chủ trung tâm mới được thay đổi phân quyền nhân sự.'], 403);
        }

        $validated = $request->validate([
            'role' => 'required|string',
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
