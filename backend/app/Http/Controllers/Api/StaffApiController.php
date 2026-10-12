<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StaffApiController extends Controller
{
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

        return [
            'invites' => [
                [
                    'id' => 1,
                    'email' => 'truongbaodi1973@gmail.com',
                    'role' => 'Giáo viên',
                    'center' => 'Khoa Anh Music Hóc Môn',
                    'status' => 'accepted', // Đã tham gia
                    'created_at' => Carbon::now()->subDays(18)->format('d/m/Y'),
                ],
            ],
            'members' => [
                [
                    'id' => 1,
                    'avatar' => '',
                    'name' => 'Khoa Anh Admin',
                    'email' => 'kfreestyle15@gmail.com',
                    'role' => 'Chủ trung tâm',
                    'permissions' => 'full', // Toàn quyền
                    'blocked_pages' => [],
                    'center' => 'Tất cả',
                    'joined_at' => '17/9/2026',
                    'is_owner' => true,
                ],
                [
                    'id' => 2,
                    'avatar' => '',
                    'name' => 'Thầy Trương Bảo Đi',
                    'email' => 'truongbaodi1973@gmail.com',
                    'role' => 'Giáo viên',
                    'permissions' => 'restricted',
                    'blocked_pages' => ['Doanh thu', 'Hóa đơn & Thu phí', 'Khách hàng tiềm năng', 'Cài đặt', 'Dữ liệu'],
                    'center' => 'Khoa Anh Music Hóc Môn',
                    'joined_at' => '23/9/2026',
                    'is_owner' => false,
                ],
            ],
            'centers' => [
                'Tất cả trung tâm',
                'Khoa Anh Music Hóc Môn',
                'Khoa Anh Music Quận 12',
                'Khoa Anh Music Gò Vấp',
            ],
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

    public function index(): JsonResponse
    {
        $data = $this->loadStaffData();

        return response()->json($data);
    }

    public function createInvite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'role' => 'required|string',
            'center' => 'required|string',
        ]);

        $data = $this->loadStaffData();
        $token = Str::random(32);
        $inviteLink = url("/invite/{$token}");

        $newInvite = [
            'id' => time(),
            'email' => $validated['email'],
            'role' => $validated['role'],
            'center' => $validated['center'],
            'status' => 'pending', // Đang chờ
            'token' => $token,
            'invite_link' => $inviteLink,
            'created_at' => Carbon::now()->format('d/m/Y'),
        ];

        // Thêm vào danh sách lời mời
        array_unshift($data['invites'], $newInvite);

        // Đồng thời thêm vào danh sách thành viên nếu người dùng muốn thấy ngay
        $newMember = [
            'id' => time(),
            'avatar' => '',
            'name' => explode('@', $validated['email'])[0],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'permissions' => 'restricted',
            'blocked_pages' => ['Doanh thu', 'Cài đặt', 'Dữ liệu'],
            'center' => $validated['center'] === 'Tất cả trung tâm' ? 'Tất cả' : $validated['center'],
            'joined_at' => Carbon::now()->format('d/m/Y'),
            'is_owner' => false,
        ];
        $data['members'][] = $newMember;

        $this->saveStaffData($data);

        return response()->json([
            'message' => 'Đã tạo lời mời thành công và copy link vào bộ nhớ tạm!',
            'invite' => $newInvite,
            'invite_link' => $inviteLink,
            'data' => $data,
        ], 201);
    }

    public function updateRole(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'role' => 'required|string',
        ]);

        $data = $this->loadStaffData();
        foreach ($data['members'] as &$member) {
            if ($member['id'] === $id && ! ($member['is_owner'] ?? false)) {
                $member['role'] = $validated['role'];
                break;
            }
        }

        $this->saveStaffData($data);

        return response()->json([
            'message' => 'Đã cập nhật vai trò nhân sự thành công!',
            'members' => $data['members'],
        ]);
    }

    public function updatePermissions(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'blocked_pages' => 'required|array',
        ]);

        $data = $this->loadStaffData();
        foreach ($data['members'] as &$member) {
            if ($member['id'] === $id && ! ($member['is_owner'] ?? false)) {
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

    public function destroy(int $id): JsonResponse
    {
        $data = $this->loadStaffData();
        $data['members'] = array_values(array_filter($data['members'], fn ($m) => $m['id'] !== $id || ($m['is_owner'] ?? false)));

        $this->saveStaffData($data);

        return response()->json([
            'message' => 'Đã xóa thành viên khỏi trung tâm thành công!',
            'members' => $data['members'],
        ]);
    }
}
