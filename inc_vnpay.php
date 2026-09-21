<?php
require_once __DIR__ . '/config.php';

/** Có cấu hình VNPay chưa (đã điền TMN_CODE + HASH_SECRET thật). */
function vnpayConfigured(): bool
{
    return VNPAY_TMN_CODE !== '' && VNPAY_HASH_SECRET !== '';
}

/**
 * Tạo URL redirect sang cổng thanh toán VNPay cho 1 đơn hàng (mã đơn phải là duy nhất, dùng
 * order_code của bảng payments). Theo đúng chuẩn ký HMAC-SHA512 của VNPay: sắp xếp tham số theo
 * tên (A-Z), nối thành query string, ký bằng HASH_SECRET, gắn vnp_SecureHash vào URL cuối cùng.
 */
function vnpayBuildPaymentUrl(string $orderCode, int $amountVnd, string $orderInfo): string
{
    $params = [
        'vnp_Version' => '2.1.0',
        'vnp_Command' => 'pay',
        'vnp_TmnCode' => VNPAY_TMN_CODE,
        'vnp_Amount' => $amountVnd * 100, // VNPay yeu cau nhan 100 (khong co phan thap phan)
        'vnp_CurrCode' => 'VND',
        'vnp_TxnRef' => $orderCode,
        'vnp_OrderInfo' => $orderInfo,
        'vnp_OrderType' => 'other',
        'vnp_Locale' => 'vn',
        'vnp_ReturnUrl' => VNPAY_RETURN_URL,
        'vnp_IpAddr' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'vnp_CreateDate' => date('YmdHis'),
        'vnp_ExpireDate' => date('YmdHis', time() + 900),
    ];
    ksort($params);

    $hashData = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $secureHash = hash_hmac('sha512', $hashData, VNPAY_HASH_SECRET);

    return VNPAY_URL . '?' . $hashData . '&vnp_SecureHash=' . $secureHash;
}

/**
 * Kiểm tra chữ ký VNPay gửi kèm khi redirect người dùng về vnp_ReturnUrl - PHẢI xác thực trước
 * khi tin bất kỳ giá trị nào trong $_GET (vnp_ResponseCode...), tránh giả mạo "thanh toán thành
 * công" bằng cách tự gõ URL redirect với response code 00.
 */
function vnpayVerifySignature(array $params): bool
{
    if (empty($params['vnp_SecureHash'])) {
        return false;
    }
    $receivedHash = $params['vnp_SecureHash'];
    unset($params['vnp_SecureHash'], $params['vnp_SecureHashType']);
    ksort($params);
    $hashData = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $expectedHash = hash_hmac('sha512', $hashData, VNPAY_HASH_SECRET);
    return hash_equals($expectedHash, $receivedHash);
}

/** Sinh 1 mã đơn hàng duy nhất cho payments.order_code. */
function vnpayGenOrderCode(): string
{
    return 'KTS' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}
