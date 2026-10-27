<?php

use App\Support;

$form = $editing ?? [];
$selectedTarget = '';
if (($form['target_type'] ?? '') === 'order' && !empty($form['target_product_id'])) {
    $selectedTarget = 'order:' . $form['target_product_id'];
} elseif (($form['target_type'] ?? '') === 'retail_order' && !empty($form['target_pack'])) {
    $selectedTarget = 'retail:' . $form['target_pack'];
}
?>

<div class="page-head">
    <div>
        <span class="eyebrow">LIÊN KẾT SẢN PHẨM</span>
        <h1>Ghép sản phẩm hai bên</h1>
        <p>Chọn gói Chungsucliqi tương ứng với từng sản phẩm trên Nas Tele.</p>
    </div>
    <form method="post" action="/sync">
        <input type="hidden" name="_csrf" value="<?= Support::csrfToken() ?>">
        <input type="hidden" name="scope" value="products">
        <button class="btn secondary">Cập nhật danh sách gói</button>
    </form>
</div>

<div class="mapping-layout">
    <form class="panel mapping-form" method="post" data-mapping-form>
        <input type="hidden" name="_csrf" value="<?= Support::csrfToken() ?>">
        <input type="hidden" name="id" value="<?= (int) ($form['id'] ?? 0) ?>">
        <input type="hidden" name="target_type" value="<?= Support::e($form['target_type'] ?? 'order') ?>" data-target-type-value>
        <input type="hidden" name="target_product_id" value="<?= Support::e($form['target_product_id'] ?? '') ?>" data-target-product-id>
        <input type="hidden" name="target_product_name" value="<?= Support::e($form['target_product_name'] ?? '') ?>" data-target-product-name>
        <input type="hidden" name="target_pack" value="<?= Support::e($form['target_pack'] ?? '') ?>" data-target-pack>

        <div class="section-head">
            <div>
                <h2><?= $editing ? 'Sửa liên kết' : 'Thêm liên kết mới' ?></h2>
                <p>Nhập sản phẩm Nas Tele rồi chọn gói xử lý bên dưới.</p>
            </div>
        </div>

        <div class="form-grid">
            <label>Mã sản phẩm trên Nas Tele<input type="number" name="nas_product_id" min="1" value="<?= Support::e($form['nas_product_id'] ?? '') ?>" required></label>
            <label>Tên sản phẩm trên Nas Tele<input name="nas_product_name" value="<?= Support::e($form['nas_product_name'] ?? '') ?>" required></label>
            <label class="full">Tên trường chứa mã chung sức<input name="event_code_field_key" value="<?= Support::e($form['event_code_field_key'] ?? '') ?>" placeholder="Ví dụ: event_code" required></label>
            <label>Tên trường chứa số điện thoại<input name="phone_field_key" value="<?= Support::e($form['phone_field_key'] ?? '') ?>" placeholder="Không bắt buộc"></label>
            <label>Tên trường chứa ghi chú<input name="note_field_key" value="<?= Support::e($form['note_field_key'] ?? '') ?>" placeholder="Không bắt buộc"></label>

            <label class="full target-select-label">
                Gói xử lý trên Chungsucliqi
                <select name="target_choice" data-target-choice required <?= !$targetProducts ? 'disabled' : '' ?>>
                    <option value="">Chọn một sản phẩm hoặc gói vé</option>
                    <?php foreach ($targetProducts as $product): ?>
                        <?php $choice = $product['target_type'] . ':' . $product['external_id']; ?>
                        <option value="<?= Support::e($choice) ?>"
                                data-type="<?= Support::e($product['target_type']) ?>"
                                data-external-id="<?= Support::e($product['external_id']) ?>"
                                data-name="<?= Support::e($product['name']) ?>"
                                <?= $choice === $selectedTarget ? 'selected' : '' ?>><?= Support::e($product['name']) ?> · <?= number_format((float) $product['price']) ?> Xu · <?= Support::e(Support::targetTypeLabel($product['target_type'])) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$targetProducts): ?>
                    <small class="field-help">Chưa có danh sách gói. Bấm “Cập nhật danh sách gói” ở phía trên trước.</small>
                <?php else: ?>
                    <small class="field-help">Mã và cách đặt đơn sẽ được điền tự động theo gói đã chọn.</small>
                <?php endif; ?>
            </label>

            <label data-retail-quantity <?= ($form['target_type'] ?? 'order') !== 'retail_order' ? 'hidden' : '' ?>>
                Số lượng gói
                <input type="number" name="target_qty" min="1" value="<?= (int) ($form['target_qty'] ?? 1) ?>">
            </label>
        </div>

        <div class="selected-target" data-selected-target <?= !$selectedTarget ? 'hidden' : '' ?>>
            <span>Gói đã chọn</span>
            <strong><?= Support::e($form['target_product_name'] ?? '') ?></strong>
        </div>

        <div class="form-actions">
            <label class="switch"><input type="checkbox" name="is_active" <?= !isset($form['is_active']) || $form['is_active'] ? 'checked' : '' ?>><span></span>Cho phép sử dụng liên kết này</label>
            <div>
                <?php if ($editing): ?><a class="btn ghost" href="/mappings">Huỷ</a><?php endif; ?>
                <button class="btn primary" <?= !$targetProducts ? 'disabled' : '' ?>>Lưu liên kết</button>
            </div>
        </div>
    </form>

    <section class="panel">
        <div class="section-head"><div><h2>Các sản phẩm đã liên kết</h2><p><?= count($mappings) ?> sản phẩm đã được thiết lập.</p></div></div>
        <div class="mapping-list">
            <?php foreach ($mappings as $mapping): ?>
                <article>
                    <div class="map-route">
                        <span><small>NAS TELE · MÃ <?= (int) $mapping['nas_product_id'] ?></small><b><?= Support::e($mapping['nas_product_name']) ?></b><em>Trường lấy mã: <?= Support::e($mapping['event_code_field_key']) ?></em></span>
                        <i>→</i>
                        <span><small><?= Support::e(Support::targetTypeLabel($mapping['target_type'])) ?></small><b><?= Support::e($mapping['target_product_name'] ?: ($mapping['target_pack'] ?: 'Mã ' . $mapping['target_product_id'])) ?></b><em><?= $mapping['is_active'] ? 'Đang sử dụng' : 'Đang tạm dừng' ?></em></span>
                    </div>
                    <div class="row-actions">
                        <a href="/mappings?edit=<?= (int) $mapping['id'] ?>">Sửa</a>
                        <form method="post" action="/mappings/delete" onsubmit="return confirm('Xoá liên kết sản phẩm này?')">
                            <input type="hidden" name="_csrf" value="<?= Support::csrfToken() ?>">
                            <input type="hidden" name="id" value="<?= (int) $mapping['id'] ?>">
                            <button class="text-button danger-text">Xoá</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (!$mappings): ?><div class="empty">Chưa có sản phẩm nào được liên kết.</div><?php endif; ?>
        </div>
    </section>
</div>
