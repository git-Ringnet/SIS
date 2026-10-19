<?php

namespace App\Models\Concerns;

use App\Support\ActivityLogger;

/**
 * Tự động ghi nhật ký hoạt động khi model được thêm, sửa, xoá.
 *
 * Model dùng trait cần khai báo hằng ACTIVITY_SUBJECT (ví dụ 'student'),
 * có thể override activityLabel() và activityContext() để hiển thị đẹp hơn.
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(function ($model) {
            ActivityLogger::log(
                'created',
                static::ACTIVITY_SUBJECT,
                $model->activityLabel(),
                $model->getKey(),
                ActivityLogger::diff([], $model->getAttributes()),
                $model->activityContext(),
            );
        });

        static::updated(function ($model) {
            $changed = $model->getChanges();
            $original = array_intersect_key($model->getOriginal(), $changed);
            $changes = ActivityLogger::diff($original, $changed);

            // Bỏ qua lần lưu chỉ đổi updated_at
            if ($changes === []) {
                return;
            }

            ActivityLogger::log(
                'updated',
                static::ACTIVITY_SUBJECT,
                $model->activityLabel(),
                $model->getKey(),
                $changes,
                $model->activityContext(),
            );
        });

        static::deleted(function ($model) {
            ActivityLogger::log(
                'deleted',
                static::ACTIVITY_SUBJECT,
                $model->activityLabel(),
                $model->getKey(),
                ActivityLogger::diff($model->getAttributes(), []),
                $model->activityContext(),
            );
        });
    }

    public function activityLabel(): ?string
    {
        return $this->name ?? null;
    }

    public function activityContext(): ?string
    {
        return null;
    }
}
