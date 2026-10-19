<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use App\Support\CenterStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CenterApiController extends Controller
{
    private function loadCentersData(): array
    {
        return CenterStore::all();
    }

    private function saveCentersData(array $data): void
    {
        CenterStore::save($data);
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

        ActivityLogger::log('created', 'center', $newCenter['name'], $newCenter['id'],
            ActivityLogger::diff([], array_diff_key($newCenter, ['rooms' => true, 'is_main' => true])));

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
                $before = $c;
                $c['name'] = $validated['name'];
                $c['address'] = $validated['address'] ?? '';
                $c['phone'] = $validated['phone'] ?? '';
                ActivityLogger::log('updated', 'center', $c['name'], $id, ActivityLogger::diff(
                    array_intersect_key($before, $validated),
                    array_intersect_key($c, $validated),
                ));
                break;
            }
        }
        unset($c);

        $this->saveCentersData($centers);

        return response()->json([
            'message' => 'Cập nhật thông tin trung tâm thành công!',
            'centers' => $centers,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $centers = $this->loadCentersData();
        $deleted = collect($centers)->firstWhere('id', $id);
        $centers = array_values(array_filter($centers, fn ($c) => $c['id'] !== $id));

        if ($deleted) {
            ActivityLogger::log('deleted', 'center', $deleted['name'], $id,
                ActivityLogger::diff(array_diff_key($deleted, ['rooms' => true, 'is_main' => true]), []));
        }

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
