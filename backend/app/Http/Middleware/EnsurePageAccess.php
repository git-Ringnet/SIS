<?php

namespace App\Http\Middleware;

use App\Support\PagePermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chặn API của các trang mà nhân sự bị chặn quyền (cấu hình ở màn Nhân sự).
 */
class EnsurePageAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $page = PagePermissions::blockedPageForRequest($request);

        if ($page !== null) {
            return response()->json([
                'message' => "Bạn không có quyền truy cập mục \"{$page}\". Vui lòng liên hệ chủ trung tâm.",
            ], 403);
        }

        return $next($request);
    }
}
