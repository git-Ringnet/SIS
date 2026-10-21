<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    private const PATH = 'staff_data.json';

    /**
     * Vai trò "Tư vấn viên" đổi tên thành "Giáo vụ" (điểm danh học viên, chỉ xem thù lao của mình).
     * Nhân sự lưu ở staff_data.json nên đổi tên trong file.
     */
    public function up(): void
    {
        $this->rename('Tư vấn viên', 'Giáo vụ');
    }

    public function down(): void
    {
        $this->rename('Giáo vụ', 'Tư vấn viên');
    }

    private function rename(string $from, string $to): void
    {
        if (! Storage::exists(self::PATH)) {
            return;
        }

        $data = json_decode(Storage::get(self::PATH), true);
        if (! is_array($data)) {
            return;
        }

        foreach ($data['members'] ?? [] as $i => $member) {
            if (($member['role'] ?? null) === $from) {
                $data['members'][$i]['role'] = $to;
            }
        }
        if (isset($data['roles']) && is_array($data['roles'])) {
            $data['roles'] = array_values(array_map(fn ($r) => $r === $from ? $to : $r, $data['roles']));
        }

        Storage::put(self::PATH, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
};
