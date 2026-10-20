<?php
// Expects: $product, $categories, $sizes, $errors, $submitLabel
?>
<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <form method="post" class="form-grid">
        <?= csrf_field() ?>

        <div>
            <label>Product Name</label>
            <input type="text" name="product_name" maxlength="100" required value="<?= e($product['product_name']) ?>">
        </div>

        <div>
            <label>Category</label>
            <select name="category_id" required>
                <option value="">-- Select --</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int)$c['category_id'] ?>" <?= (int)$product['category_id'] === (int)$c['category_id'] ? 'selected' : '' ?>>
                        <?= e($c['category_name']) ?><?= $c['is_active'] ? '' : ' (inactive)' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label>Size</label>
            <select name="size_type_id">
                <option value="">-- None --</option>
                <?php foreach ($sizes as $s): ?>
                    <option value="<?= (int)$s['size_type_id'] ?>" <?= (int)$product['size_type_id'] === (int)$s['size_type_id'] ? 'selected' : '' ?>>
                        <?= e($s['name'] . ' (' . $s['code'] . ')') ?><?= $s['is_active'] ? '' : ' (inactive)' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label>Description</label>
            <input type="text" name="description" value="<?= e($product['description']) ?>">
        </div>

        <label class="check">
            <input type="checkbox" name="is_active" <?= $product['is_active'] ? 'checked' : '' ?>> Active
        </label>

        <div class="actions">
            <button class="btn" type="submit"><?= e($submitLabel) ?></button>
            <a class="btn btn-secondary" href="products.php">Cancel</a>
        </div>
    </form>
</div>