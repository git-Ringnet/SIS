<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

abstract class Controller
{
    protected const PER_PAGE_OPTIONS = [10, 20, 50, 100];

    protected const DEFAULT_PER_PAGE = 20;

    /**
     * Chỉ phân trang khi frontend gửi ?page=, các chỗ cần toàn bộ danh sách
     * (ví dụ dropdown chọn học viên) không gửi page nên vẫn nhận đủ dữ liệu.
     */
    protected function wantsPagination(Request $request): bool
    {
        return $request->filled('page');
    }

    /**
     * Số dòng mỗi trang lấy từ ?per_page=, chỉ chấp nhận các giá trị trong PER_PAGE_OPTIONS.
     */
    protected function perPage(Request $request): int
    {
        $perPage = (int) $request->input('per_page', static::DEFAULT_PER_PAGE);

        return in_array($perPage, static::PER_PAGE_OPTIONS, true) ? $perPage : static::DEFAULT_PER_PAGE;
    }

    /**
     * Phân trang một mảng đã lọc sẵn trong PHP (dữ liệu ghép từ nhiều nguồn, không phải query).
     */
    protected function paginateArray(array $items, Request $request): LengthAwarePaginator
    {
        $perPage = $this->perPage($request);
        $page = max(1, (int) $request->input('page', 1));

        return new Paginator(
            array_slice(array_values($items), ($page - 1) * $perPage, $perPage),
            count($items),
            $perPage,
            $page,
        );
    }

    /**
     * Thông tin phân trang trả về cho frontend (component Pagination.vue).
     */
    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
