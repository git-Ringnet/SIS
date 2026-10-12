<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CenterApiController extends Controller
{
    private function getCentersDataPath(): string
    {
        return 'centers_data.json';
    }

    private function loadCentersData(): array
    {
        $path = $this->getCentersDataPath();
        if (Storage::exists($path)) {
            $data = json_decode(Storage::get($path), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return [
            [
                'id' => 1,
                'name' => 'Khoa Anh Music Hóc Môn',
                'address' => '123 Tô Ký, Xã Thới Tam Thôn, Hóc Môn, TP.HCM',
                'phone' => '0792324514',
                'is_main' => true,
                'rooms' => [
                    ['id' => 101, 'name' => 'GROUP', 'capacity' => 6, 'description' => 'Phòng học nhóm hòa tấu / nhạc lý'],
                    ['id' => 102, 'name' => 'PIANO 01', 'capacity' => 1, 'description' => 'Phòng Piano cơ 1-1 chuyên sâu'],
                    ['id' => 103, 'name' => 'PIANO 02', 'capacity' => 1, 'description' => 'Phòng Piano điện thực hành'],
                    ['id' => 104, 'name' => 'GUITAR 01', 'capacity' => 4, 'description' => 'Phòng Guitar đệm hát & Classic'],
                    ['id' => 105, 'name' => 'VOCAL 01', 'capacity' => 5, 'description' => 'Phòng Thanh nhạc cách âm'],
                    ['id' => 106, 'name' => 'DRUM 01', 'capacity' => 2, 'description' => 'Phòng Trống jazz tiêu âm'],
                ],
            ],
            [
                'id' => 2,
                'name' => 'Khoa Anh Music Quận 12',
                'address' => '45 Lê Văn Khương, Phường Thới An, Quận 12, TP.HCM',
                'phone' => '0912345678',
                'is_main' => false,
                'rooms' => [
                    ['id' => 201, 'name' => 'PIANO Q12', 'capacity' => 2, 'description' => 'Phòng Piano 1:2'],
                    ['id' => 202, 'name' => 'GUITAR Q12', 'capacity' => 4, 'description' => 'Phòng Guitar nhóm'],
                ],
            ],
            [
                'id' => 3,
                'name' => 'Khoa Anh Music Gò Vấp',
                'address' => '78 Quang Trung, Phường 10, Gò Vấp, TP.HCM',
                'phone' => '0987654321',
                'is_main' => false,
                'rooms' => [
                    ['id' => 301, 'name' => 'STUDIO GV', 'capacity' => 6, 'description' => 'Phòng đa năng'],
                ],
            ],
        ];
    }

    private function saveCentersData(array $data): void
    {
        Storage::put($this->getCentersDataPath(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function index(): JsonResponse
    {
        $centers = $this->loadCentersData();

        return response()->json([
            'centers' => $centers,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
        ]);

        $centers = $this->loadCentersData();
        $newCenter = [
            'id' => time(),
            'name' => $validated['name'],
            'address' => $validated['address'] ?? '',
            'phone' => $validated['phone'] ?? '',
            'is_main' => count($centers) === 0,
            'rooms' => [
                ['id' => time() + 1, 'name' => 'Phòng 01', 'capacity' => 4, 'description' => 'Phòng học chính'],
            ],
        ];

        $centers[] = $newCenter;
        $this->saveCentersData($centers);

        return response()->json([
            'message' => 'Tạo trung tâm mới thành công!',
            'center' => $newCenter,
            'centers' => $centers,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
        ]);

        $centers = $this->loadCentersData();
        foreach ($centers as &$c) {
            if ($c['id'] === $id) {
                $c['name'] = $validated['name'];
                $c['address'] = $validated['address'] ?? '';
                $c['phone'] = $validated['phone'] ?? '';
                break;
            }
        }

        $this->saveCentersData($centers);

        return response()->json([
            'message' => 'Cập nhật thông tin trung tâm thành công!',
            'centers' => $centers,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $centers = $this->loadCentersData();
        $centers = array_values(array_filter($centers, fn ($c) => $c['id'] !== $id));

        $this->saveCentersData($centers);

        return response()->json([
            'message' => 'Đã xóa trung tâm thành công!',
            'centers' => $centers,
        ]);
    }

    public function addRoom(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'capacity' => 'required|integer|min:1',
            'description' => 'nullable|string|max:255',
        ]);

        $centers = $this->loadCentersData();
        $newRoom = [
            'id' => time(),
            'name' => $validated['name'],
            'capacity' => (int) $validated['capacity'],
            'description' => $validated['description'] ?? '',
        ];

        foreach ($centers as &$c) {
            if ($c['id'] === $id) {
                $c['rooms'][] = $newRoom;
                break;
            }
        }

        $this->saveCentersData($centers);

        return response()->json([
            'message' => 'Thêm phòng học thành công!',
            'room' => $newRoom,
            'centers' => $centers,
        ]);
    }

    public function deleteRoom(int $centerId, int $roomId): JsonResponse
    {
        $centers = $this->loadCentersData();
        foreach ($centers as &$c) {
            if ($c['id'] === $centerId) {
                $c['rooms'] = array_values(array_filter($c['rooms'], fn ($r) => $r['id'] !== $roomId));
                break;
            }
        }

        $this->saveCentersData($centers);

        return response()->json([
            'message' => 'Đã xóa phòng học thành công!',
            'centers' => $centers,
        ]);
    }
}
