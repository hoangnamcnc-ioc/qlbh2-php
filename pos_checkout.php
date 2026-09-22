<?php
require_once __DIR__ . '/inc_auth.php';
require_once __DIR__ . '/inc_functions.php';
$user = requireLogin();
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $input['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Phiên làm việc không hợp lệ, vui lòng tải lại trang.']);
    exit;
}

$branchId = effectiveBranchId($user);
$tenantId = currentTenantId();
if (!$branchId) {
    echo json_encode(['error' => 'Tài khoản chưa được gán chi nhánh, không thể tạo đơn']);
    exit;
}

$items = $input['items'] ?? [];
if (!is_array($items) || !$items) {
    echo json_encode(['error' => 'Đơn hàng chưa có sản phẩm']);
    exit;
}

$paymentMethod = in_array($input['payment_method'] ?? '', ['CASH', 'BANK_TRANSFER', 'CARD', 'QR_CODE'], true)
    ? $input['payment_method']
    : 'CASH';
$customerPhone = trim((string) ($input['customer_phone'] ?? ''));
$manualDiscountType = ($input['manual_discount_type'] ?? '') === 'PERCENT' ? 'PERCENT' : 'AMOUNT';
$manualDiscountValue = max(0, (float) ($input['manual_discount_value'] ?? 0));
$isDelivery = !empty($input['is_delivery']);
$deliveryAddress = $isDelivery ? trim((string) ($input['delivery_address'] ?? '')) : null;
$shippingFee = $isDelivery ? max(0, (float) ($input['shipping_fee'] ?? 0)) : 0.0;
$orderNote = trim((string) ($input['note'] ?? '')) ?: null;
$orderTags = trim((string) ($input['tags'] ?? '')) ?: null;
$paidAmountInput = $input['paid_amount'] ?? null;
$paidAmountInput = $paidAmountInput === null || $paidAmountInput === '' ? null : max(0, (float) $paidAmountInput);
$isDraft = !empty($input['draft']);
$sourceIdInput = (int) ($input['source_id'] ?? 0) ?: null;

$pdo = db();
$allowNegativeStock = getSetting('allow_negative_stock', '0') === '1';

// Gia ban tung dong khong duoc thap hon ti le nay so voi gia niem yet - cung nguong voi tran
// chiet khau toan don ben duoi ($discount <= 50% $subTotal) de hai co che khong mau thuan nhau.
const POS_MIN_PRICE_RATIO = 0.5;

$sourceId = null;
if ($sourceIdInput) {
    $srcStmt = $pdo->prepare('SELECT id FROM order_sources WHERE id = ? AND is_active = 1 AND tenant_id = ?');
    $srcStmt->execute([$sourceIdInput, $tenantId]);
    $sourceId = $srcStmt->fetchColumn() ?: null;
}

try {
    $pdo->beginTransaction();

    $customerId = null;
    if ($customerPhone !== '') {
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE phone = ? AND tenant_id = ?');
        $stmt->execute([$customerPhone, $tenantId]);
        $existingCustomer = $stmt->fetch();
        if ($existingCustomer) {
            $customerId = (int) $existingCustomer['id'];
        } else {
            $code = 'CUZN' . substr((string) (int) round(microtime(true) * 1000), -8);
            $pdo->prepare('INSERT INTO customers (code, name, phone, tenant_id) VALUES (?, ?, ?, ?)')
                ->execute([$code, $customerPhone, $customerPhone, $tenantId]);
            $customerId = (int) $pdo->lastInsertId();
        }
    }

    // Bang gia rieng theo nhom khach (vd gia si). Phai nap TRUOC vong lap san pham vi san gia
    // ben duoi lay gia trong bang gia nay lam moc - neu van lay gia le lam moc thi moi don ban
    // si (thuong chi bang 30-50% gia le) deu bi chan oan.
    $priceListOverrides = [];
    if ($customerId) {
        $plStmt = $pdo->prepare(
            'SELECT g.price_list_id FROM customers c
             JOIN customer_groups g ON g.id = c.group_id AND g.tenant_id = c.tenant_id
             WHERE c.id = ? AND c.tenant_id = ?'
        );
        $plStmt->execute([$customerId, $tenantId]);
        $priceListId = (int) ($plStmt->fetchColumn() ?: 0);
        if ($priceListId) {
            $ppStmt = $pdo->prepare(
                'SELECT pp.product_id, pp.variant_id, pp.price FROM product_prices pp
                 JOIN price_lists pl ON pl.id = pp.price_list_id
                 WHERE pp.price_list_id = ? AND pl.tenant_id = ?'
            );
            $ppStmt->execute([$priceListId, $tenantId]);
            foreach ($ppStmt->fetchAll() as $row) {
                $priceListOverrides[$row['product_id'] . ':' . ($row['variant_id'] ?? '')] = (float) $row['price'];
            }
        }
    }

    $subTotal = 0.0;
    $lineData = [];

    foreach ($items as $line) {
        $productId = (int) ($line['product_id'] ?? 0);
        $variantId = (int) ($line['variant_id'] ?? 0) ?: null;
        $quantity = round((float) ($line['quantity'] ?? 0), 3);
        $unitPrice = (float) ($line['unit_price'] ?? 0);
        if ($productId <= 0 || $quantity <= 0) {
            throw new RuntimeException('Dữ liệu sản phẩm không hợp lệ');
        }

        // Xac nhan san pham thuc su thuoc tenant hien tai truoc khi ban - chan viec doan
        // product_id cua tenant khac de tao don hang/tru kho gia mao.
        $typeStmt = $pdo->prepare('SELECT product_type, cost_price, sell_price, name FROM products WHERE id = ? AND tenant_id = ?');
        $typeStmt->execute([$productId, $tenantId]);
        $typeRow = $typeStmt->fetch();
        if (!$typeRow) {
            throw new RuntimeException('Sản phẩm không hợp lệ');
        }
        $productType = $typeRow['product_type'];
        $listedPrice = (float) $typeRow['sell_price'];
        $productName = (string) $typeRow['name'];
        // Chot gia von TAI THOI DIEM BAN vao chinh dong don hang - tranh loi bao cao lai gop qua
        // khu bi tinh lai sai moi khi gia nhap san pham thay doi sau nay (chi dung gia von hien
        // tai lam fallback cho du lieu cu chua co cost_price rieng, xem fix_add_cost_price.php).
        $costPrice = (float) $typeRow['cost_price'];

        if ($variantId) {
            $ownVariant = $pdo->prepare('SELECT id, cost_price, sell_price, name FROM product_variants WHERE id = ? AND product_id = ? AND tenant_id = ?');
            $ownVariant->execute([$variantId, $productId, $tenantId]);
            $variantRow = $ownVariant->fetch();
            if (!$variantRow) {
                throw new RuntimeException('Biến thể sản phẩm không hợp lệ');
            }
            $costPrice = (float) $variantRow['cost_price'];
            $listedPrice = (float) $variantRow['sell_price'];
            $productName .= ' - ' . $variantRow['name'];
        }

        // Neu khach thuoc nhom co bang gia rieng (gia si...) thi moc so sanh la gia trong bang
        // gia do, khong phai gia le - nguoc lai se chan nham chinh gia ma cua hang da cau hinh.
        $overrideKey = $productId . ':' . ($variantId ?? '');
        $priceLabel = 'giá niêm yết';
        if (isset($priceListOverrides[$overrideKey])) {
            $listedPrice = $priceListOverrides[$overrideKey];
            $priceLabel = 'giá theo bảng giá riêng của khách này';
        }

        // Chot san gia ban PHIA MAY CHU. POS cho phep sua gia tung dong (tinh nang co y, de mac
        // ca tai quay), nhung gia gui len tu trinh duyet KHONG duoc tin tuyet doi: neu khong
        // kiem tra, chi can sua request la ban duoc gia 0d, va tran chiet khau 50% ben duoi cung
        // bi vo hieu vi no tinh theo chinh $subTotal do client quyet dinh.
        // Dung cung nguong 50% voi tran chiet khau de nhat quan: muon ban re hon nua thi sua gia
        // niem yet cua san pham, khong lam ngam qua tung don.
        if ($listedPrice > 0 && $unitPrice < $listedPrice * POS_MIN_PRICE_RATIO) {
            $sanGia = money($listedPrice * POS_MIN_PRICE_RATIO);
            throw new RuntimeException(
                "Giá bán của \"$productName\" thấp hơn mức cho phép (tối thiểu $sanGia, tức "
                . (int) (POS_MIN_PRICE_RATIO * 100) . "% $priceLabel). "
                . 'Nếu muốn bán rẻ hơn, hãy sửa lại giá bán của sản phẩm (hoặc bảng giá riêng) trước.'
            );
        }

        if ($productType === 'SERVICE') {
            // Dịch vụ không quản lý tồn kho, không cần trừ kho.
        } elseif ($productType === 'COMBO') {
            $comboStmt = $pdo->prepare('SELECT component_product_id, quantity AS comp_qty FROM combo_items WHERE combo_product_id = ?');
            $comboStmt->execute([$productId]);
            $components = $comboStmt->fetchAll();
            if (!$components) {
                throw new RuntimeException('Combo này chưa có sản phẩm thành phần, không thể bán');
            }
            foreach ($components as $comp) {
                $needQty = (float) $comp['comp_qty'] * $quantity;
                $stmt = $pdo->prepare(
                    'SELECT * FROM inventory WHERE product_id = ? AND branch_id = ? AND variant_id IS NULL FOR UPDATE'
                );
                $stmt->execute([$comp['component_product_id'], $branchId]);
                $compInv = $stmt->fetch();
                if (!$allowNegativeStock && (!$compInv || (float) $compInv['quantity'] < $needQty)) {
                    $prod = $pdo->prepare('SELECT name FROM products WHERE id = ?');
                    $prod->execute([$comp['component_product_id']]);
                    $pname = $prod->fetchColumn() ?: ('#' . $comp['component_product_id']);
                    throw new RuntimeException("Sản phẩm thành phần \"$pname\" trong combo không đủ tồn kho");
                }
                if ($compInv) {
                    $pdo->prepare('UPDATE inventory SET quantity = quantity - ? WHERE id = ?')
                        ->execute([$needQty, $compInv['id']]);
                } else {
                    $pdo->prepare('INSERT INTO inventory (branch_id, product_id, variant_id, quantity) VALUES (?,?,NULL,?)')
                        ->execute([$branchId, $comp['component_product_id'], -$needQty]);
                }
            }
        } else {
            if ($variantId) {
                $stmt = $pdo->prepare('SELECT * FROM inventory WHERE variant_id = ? AND branch_id = ? FOR UPDATE');
                $stmt->execute([$variantId, $branchId]);
            } else {
                $stmt = $pdo->prepare(
                    'SELECT * FROM inventory WHERE product_id = ? AND branch_id = ? AND variant_id IS NULL FOR UPDATE'
                );
                $stmt->execute([$productId, $branchId]);
            }
            $inv = $stmt->fetch();

            if (!$allowNegativeStock && (!$inv || (float) $inv['quantity'] < $quantity)) {
                if ($variantId) {
                    $prod = $pdo->prepare(
                        "SELECT CONCAT(p.name, ' - ', v.name) AS name FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = ?"
                    );
                    $prod->execute([$variantId]);
                } else {
                    $prod = $pdo->prepare('SELECT name FROM products WHERE id = ?');
                    $prod->execute([$productId]);
                }
                $pname = $prod->fetchColumn() ?: ('#' . $productId);
                $tonHienCo = $inv ? fmtQty($inv['quantity']) : '0';
                // Bao ro dang co bao nhieu va cach xu ly - truoc day chi bao "khong du ton kho"
                // la ngo cut voi nguoi dung moi (vua tao san pham xong, ton kho = 0).
                throw new RuntimeException(
                    "Sản phẩm \"$pname\" không đủ tồn kho (còn $tonHienCo). "
                    . 'Vào Sản phẩm → Quản lý kho → "Nhập tồn kho từ file" để khai báo tồn đầu kỳ, '
                    . 'hoặc bật "Cho phép bán khi hết hàng" trong Cấu hình.'
                );
            }

            if ($inv) {
                $pdo->prepare('UPDATE inventory SET quantity = quantity - ? WHERE id = ?')
                    ->execute([$quantity, $inv['id']]);
            } else {
                $pdo->prepare('INSERT INTO inventory (branch_id, product_id, variant_id, quantity) VALUES (?,?,?,?)')
                    ->execute([$branchId, $productId, $variantId, -$quantity]);
            }

            // Trừ lô hàng theo FEFO (hết hạn sớm nhất trước) nếu sản phẩm có khai báo lô — sổ
            // phụ theo dõi hạn sử dụng, không phải nguồn dữ liệu tồn kho chính.
            if (!$variantId) {
                $remaining = $quantity;
                $batchStmt = $pdo->prepare(
                    'SELECT * FROM product_batches WHERE product_id = ? AND branch_id = ? AND quantity > 0
                     ORDER BY (expiry_date IS NULL), expiry_date FOR UPDATE'
                );
                $batchStmt->execute([$productId, $branchId]);
                foreach ($batchStmt->fetchAll() as $batch) {
                    if ($remaining <= 0) break;
                    $take = min($remaining, (float) $batch['quantity']);
                    $pdo->prepare('UPDATE product_batches SET quantity = quantity - ? WHERE id = ?')->execute([$take, $batch['id']]);
                    $remaining -= $take;
                }
            }
        }

        $lineTotal = $unitPrice * $quantity;
        $subTotal += $lineTotal;
        $lineData[] = [$productId, $variantId, $quantity, $unitPrice, $lineTotal, $costPrice];
    }

    // Chiết khấu đơn nhập tay (F6) tính trước, luôn giới hạn không vượt quá tổng tiền hàng
    $manualDiscount = $manualDiscountType === 'PERCENT'
        ? $subTotal * $manualDiscountValue / 100
        : $manualDiscountValue;
    $discount = min($subTotal, max(0, $manualDiscount));

    // Xác thực mã giảm giá lại phía server (không tin số liệu client gửi lên)
    $couponCode = null;
    $couponId = null;
    $rawCouponCode = strtoupper(trim((string) ($input['coupon_code'] ?? '')));
    if ($rawCouponCode !== '') {
        $cStmt = $pdo->prepare('SELECT * FROM coupons WHERE code = ? AND tenant_id = ? FOR UPDATE');
        $cStmt->execute([$rawCouponCode, $tenantId]);
        $coupon = $cStmt->fetch();

        if (
            $coupon && $coupon['is_active']
            && (!$coupon['start_date'] || strtotime($coupon['start_date']) <= time())
            && (!$coupon['end_date'] || strtotime($coupon['end_date'] . ' 23:59:59') >= time())
            && (!$coupon['max_uses'] || (int) $coupon['used_count'] < (int) $coupon['max_uses'])
            && $subTotal >= (float) $coupon['min_order_amount']
        ) {
            $couponDiscount = $coupon['discount_type'] === 'PERCENT'
                ? $subTotal * (float) $coupon['discount_value'] / 100
                : (float) $coupon['discount_value'];
            $discount = min($subTotal, $discount + $couponDiscount);
            $couponCode = $coupon['code'];
            $couponId = $coupon['id'];
        }
    }

    // Chiết khấu khách hàng thân thiết: lấy mức cao hơn giữa chiết khấu riêng của khách
    // và chiết khấu theo hạng thẻ (tính theo tổng chi tiêu lũy kế các đơn chưa hủy), cộng dồn tiếp.
    if ($customerId) {
        $custStmt = $pdo->prepare('SELECT discount_percent FROM customers WHERE id = ?');
        $custStmt->execute([$customerId]);
        $loyaltyDiscountPercent = (float) ($custStmt->fetchColumn() ?: 0);

        $spendStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE customer_id = ? AND status != 'CANCELLED'"
        );
        $spendStmt->execute([$customerId]);
        $totalSpend = (float) $spendStmt->fetchColumn();

        $tierStmt = $pdo->prepare(
            'SELECT discount_percent FROM customer_tiers WHERE is_active = 1 AND min_spend <= ? AND tenant_id = ? ORDER BY min_spend DESC LIMIT 1'
        );
        $tierStmt->execute([$totalSpend, $tenantId]);
        $tierDiscountPercent = (float) ($tierStmt->fetchColumn() ?: 0);

        $loyaltyDiscountPercent = max($loyaltyDiscountPercent, $tierDiscountPercent);
        if ($loyaltyDiscountPercent > 0) {
            $discount = min($subTotal, $discount + $subTotal * $loyaltyDiscountPercent / 100);
        }
    }

    // Áp dụng tự động chương trình khuyến mại phù hợp nhất (không cần nhập mã),
    // cộng dồn với giảm giá từ coupon nếu có.
    $promotionId = null;
    $pStmt = $pdo->prepare(
        "SELECT * FROM promotions WHERE is_active = 1 AND min_order_amount <= ? AND tenant_id = ?
         AND (start_date IS NULL OR start_date <= CURDATE())
         AND (end_date IS NULL OR end_date >= CURDATE())
         ORDER BY discount_percent DESC LIMIT 1"
    );
    $pStmt->execute([$subTotal, $tenantId]);
    $promotion = $pStmt->fetch();
    if ($promotion) {
        $promoDiscount = $subTotal * (float) $promotion['discount_percent'] / 100;
        $discount = min($subTotal, $discount + $promoDiscount);
        $promotionId = $promotion['id'];
    }

    // Tran an toan: du cong don bao nhieu loai chiet khau (tay + coupon + hang khach + khuyen
    // mai), tong khong duoc vuot qua 50% gia tri don hang - tranh loi cau hinh/lam dung nhieu
    // loai giam gia cung luc lam mot don bi giam gia qua sau ngoai y muon cua chu cua hang.
    $discount = min($discount, $subTotal * 0.5);

    $totalAmount = $subTotal - $discount;
    if (getSetting('round_total', '0') === '1') {
        $totalAmount = round($totalAmount / 1000) * 1000;
    }
    $totalAmount += $shippingFee;

    if ($isDraft) {
        $paidAmount = 0.0;
        $paymentStatus = 'UNPAID';
    } elseif ($paidAmountInput === null || $paidAmountInput >= $totalAmount) {
        $paidAmount = $totalAmount;
    } elseif (!$customerId) {
        throw new RuntimeException('Chỉ có thể cho khách nợ một phần khi đã chọn khách hàng (nhập SĐT)');
    } else {
        $paidAmount = max(0, $paidAmountInput);
    }
    if (!$isDraft) {
        $paymentStatus = $paidAmount >= $totalAmount ? 'PAID' : ($paidAmount > 0 ? 'PARTIAL' : 'UNPAID');
    }
    $initialStatus = $isDraft ? 'DRAFT' : 'COMPLETED';

    $code = 'DH' . strtoupper(base_convert((string) (microtime(true) * 1000), 10, 36));

    $pdo->prepare(
        'INSERT INTO orders (code, branch_id, customer_id, sold_by_id, source, source_id, status, payment_status, sub_total, discount, coupon_code, promotion_id, shipping_fee, shipping_address, is_delivery, note, tags, total_amount, paid_amount)
         VALUES (?, ?, ?, ?, "POS", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$code, $branchId, $customerId, $user['id'], $sourceId, $initialStatus, $paymentStatus, $subTotal, $discount, $couponCode, $promotionId, $shippingFee, $deliveryAddress, $isDelivery ? 1 : 0, $orderNote, $orderTags, $totalAmount, $paidAmount]);
    $orderId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO order_status_history (order_id, from_status, to_status, changed_by_id) VALUES (?, NULL, ?, ?)')
        ->execute([$orderId, $initialStatus, $user['id']]);

    $itemStmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, variant_id, quantity, unit_price, line_total, cost_price) VALUES (?,?,?,?,?,?,?)'
    );
    foreach ($lineData as [$productId, $variantId, $quantity, $unitPrice, $lineTotal, $costPrice]) {
        $itemStmt->execute([$orderId, $productId, $variantId, $quantity, $unitPrice, $lineTotal, $costPrice]);
    }

    if ($couponId) {
        $pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?')->execute([$couponId]);
    }

    if (!$isDraft) {
        createWarrantyCardsForOrder($orderId, $customerId, $user['id']);

        if ($paidAmount > 0) {
            $pdo->prepare('INSERT INTO payments (order_id, method, amount) VALUES (?,?,?)')
                ->execute([$orderId, $paymentMethod, $paidAmount]);
            recordCashbookEntry($branchId, 'RECEIPT', $paidAmount, "Thu tiền bán hàng $code", $paymentMethod, $user['id'], $orderId);
        }

        if ($customerId) {
            $points = (int) floor($totalAmount / 10000);
            $pdo->prepare('UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?')
                ->execute([$points, $customerId]);
            $unpaid = $totalAmount - $paidAmount;
            if ($unpaid > 0) {
                $pdo->prepare('UPDATE customers SET debt = debt + ? WHERE id = ?')->execute([$unpaid, $customerId]);
                $pdo->prepare('INSERT INTO customer_debt_entries (customer_id, order_id, amount, note, created_by_id) VALUES (?,?,?,?,?)')
                    ->execute([$customerId, $orderId, $unpaid, 'Bán hàng chưa thanh toán đủ', $user['id']]);
            }
        }
    }

    $pdo->commit();
    echo json_encode(['order_id' => $orderId, 'code' => $code]);
} catch (RuntimeException $ex) {
    $pdo->rollBack();
    echo json_encode(['error' => $ex->getMessage()]);
} catch (Throwable $ex) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Không thể tạo đơn hàng, vui lòng thử lại']);
}
