<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use App\Support\CenterStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
            'invoice_prefix' => 'nullable|string|max:30',
            'logo_url' => 'nullable|string|max:2000',
            'bank_info' => 'nullable|string|max:5000',
            'qr_url' => 'nullable|string|max:2000',
            // Sĩ số lớp nhóm: số HV đi học ≤ một nửa thì giáo viên nhận 50% lương ca
            'group_size' => 'nullable|integer|min:2|max:100',
        ]);

        $centers = $this->loadCentersData();
        $newCenter = [
            'id' => time(),
            'name' => $validated['name'],
            'address' => $validated['address'] ?? '',
            'phone' => $validated['phone'] ?? '',
            'invoice_prefix' => $validated['invoice_prefix'] ?? '',
            'logo_url' => $validated['logo_url'] ?? '',
            'bank_info' => $validated['bank_info'] ?? '',
            'qr_url' => $validated['qr_url'] ?? '',
            'group_size' => $validated['group_size'] ?? null,
            'is_main' => count($centers) === 0,
            'rooms' => [],
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
            'invoice_prefix' => 'nullable|string|max:30',
            'logo_url' => 'nullable|string|max:2000',
            'bank_info' => 'nullable|string|max:5000',
            'qr_url' => 'nullable|string|max:2000',
            // Sĩ số lớp nhóm: số HV đi học ≤ một nửa thì giáo viên nhận 50% lương ca
            'group_size' => 'nullable|integer|min:2|max:100',
        ]);

        $centers = $this->loadCentersData();
        foreach ($centers as &$c) {
            if ($c['id'] === $id) {
                $before = $c;
                $c['name'] = $validated['name'];
                $c['address'] = $validated['address'] ?? '';
                $c['phone'] = $validated['phone'] ?? '';
                $c['invoice_prefix'] = $validated['invoice_prefix'] ?? '';
                $c['logo_url'] = $validated['logo_url'] ?? '';
                $c['bank_info'] = $validated['bank_info'] ?? '';
                $c['qr_url'] = $validated['qr_url'] ?? '';
                $c['group_size'] = $validated['group_size'] ?? null;
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

    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $path = $request->file('file')->store('centers', 'public');
        $url = Storage::url($path);

        return response()->json([
            'message' => 'Tải ảnh thành công!',
            'url' => $url,
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
