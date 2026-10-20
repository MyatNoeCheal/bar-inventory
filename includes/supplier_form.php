<?php
// Expects: $supplier, $errors, $submitLabel
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
            <label>Supplier Name</label>
            <input type="text" name="supplier_name" maxlength="100" required value="<?= e($supplier['supplier_name']) ?>">
        </div>
        <div>
            <label>Phone</label>
            <input type="text" name="phone" maxlength="30" value="<?= e($supplier['phone']) ?>">
        </div>
        <div>
            <label>Address</label>
            <input type="text" name="address" maxlength="255" value="<?= e($supplier['address']) ?>">
        </div>
        <div>
            <label>Description</label>
            <input type="text" name="description" value="<?= e($supplier['description']) ?>">
        </div>

        <label class="check">
            <input type="checkbox" name="is_active" <?= $supplier['is_active'] ? 'checked' : '' ?>> Active
        </label>

        <div class="actions">
            <button class="btn" type="submit"><?= e($submitLabel) ?></button>
            <a class="btn btn-secondary" href="suppliers.php">Cancel</a>
        </div>
    </form>
</div>