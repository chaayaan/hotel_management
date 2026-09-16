<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/restaurant_functions.php';
require_login();

$user = current_user();
$size_options = ['1:1', '1:2', '1:4', 'Half', 'Full', 'Regular'];

// Upload location for food item images: /upload/resturent/food/ (relative to site root)
define('FOOD_IMG_UPLOAD_DIR', __DIR__ . '/upload/resturent/food/');
define('FOOD_IMG_UPLOAD_URL', 'upload/resturent/food/');
define('FOOD_IMG_MAX_BYTES', 2 * 1024 * 1024); // 2MB
$FOOD_IMG_ALLOWED = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

/**
 * Handle an uploaded food item image.
 * Returns ['ok' => bool, 'path' => string|null, 'error' => string|null]
 * $path is the relative web path (e.g. upload/resturent/food/xxxx.jpg) to store in the DB.
 */
function handle_food_image_upload($file, $allowed_types, $existing_path = null) {
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        // No new file uploaded — keep whatever existing path was passed in.
        return ['ok' => true, 'path' => $existing_path, 'error' => null];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => 'Image upload failed (error code ' . $file['error'] . ').'];
    }

    if ($file['size'] > FOOD_IMG_MAX_BYTES) {
        return ['ok' => false, 'path' => null, 'error' => 'Image is too large. Max size is 2MB.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed_types[$mime])) {
        return ['ok' => false, 'path' => null, 'error' => 'Invalid image type. Allowed: JPG, PNG, WEBP, GIF.'];
    }

    if (!is_dir(FOOD_IMG_UPLOAD_DIR)) {
        if (!mkdir(FOOD_IMG_UPLOAD_DIR, 0755, true) && !is_dir(FOOD_IMG_UPLOAD_DIR)) {
            return ['ok' => false, 'path' => null, 'error' => 'Could not create upload directory.'];
        }
    }

    $ext = $allowed_types[$mime];
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = FOOD_IMG_UPLOAD_DIR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['ok' => false, 'path' => null, 'error' => 'Failed to save uploaded image.'];
    }

    // Remove the old image file if we're replacing it and it lives in our upload dir.
    if ($existing_path && strpos($existing_path, FOOD_IMG_UPLOAD_URL) === 0) {
        $old_file = __DIR__ . '/' . $existing_path;
        if (is_file($old_file)) {
            @unlink($old_file);
        }
    }

    return ['ok' => true, 'path' => FOOD_IMG_UPLOAD_URL . $filename, 'error' => null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        flash_set('danger', 'Invalid request token. Please try again.');
        header('Location: restaurant_food_items.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $food_category = (int)($_POST['food_category'] ?? 0);
        $item_name = trim($_POST['item_name'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $size = trim($_POST['size'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $remove_image = isset($_POST['remove_image']) && $_POST['remove_image'] === '1';

        if ($food_category <= 0 || $item_name === '' || $price < 0) {
            flash_set('danger', 'Please fill all required fields correctly.');
            header('Location: restaurant_food_items.php');
            exit;
        }

        $existing_image = null;
        $id = (int)($_POST['id'] ?? 0);
        if ($action === 'update' && $id > 0) {
            $curStmt = mysqli_prepare($conn, "SELECT image_path FROM restaurant_food_items WHERE id = ?");
            mysqli_stmt_bind_param($curStmt, 'i', $id);
            mysqli_stmt_execute($curStmt);
            $curRow = mysqli_fetch_assoc(mysqli_stmt_get_result($curStmt));
            $existing_image = $curRow ? $curRow['image_path'] : null;
        }

        if ($remove_image) {
            if ($existing_image && strpos($existing_image, FOOD_IMG_UPLOAD_URL) === 0) {
                $old_file = __DIR__ . '/' . $existing_image;
                if (is_file($old_file)) @unlink($old_file);
            }
            $existing_image = null;
        }

        $upload = handle_food_image_upload($_FILES['image_file'] ?? null, $FOOD_IMG_ALLOWED, $existing_image);
        if (!$upload['ok']) {
            flash_set('danger', $upload['error']);
            header('Location: restaurant_food_items.php');
            exit;
        }
        $image_path = $upload['path'];

        if ($action === 'create') {
            $stmt = mysqli_prepare($conn, "INSERT INTO restaurant_food_items (food_category, item_name, price, size, image_path, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'isdssii', $food_category, $item_name, $price, $size, $image_path, $is_active, $user['id']);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Food item added successfully.');
            } else {
                flash_set('danger', 'Failed to add food item: ' . mysqli_error($conn));
            }
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE restaurant_food_items SET food_category=?, item_name=?, price=?, size=?, image_path=?, is_active=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'isdssii', $food_category, $item_name, $price, $size, $image_path, $is_active, $id);
            if (mysqli_stmt_execute($stmt)) {
                flash_set('success', 'Food item updated successfully.');
            } else {
                flash_set('danger', 'Failed to update food item: ' . mysqli_error($conn));
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        $check = mysqli_prepare($conn, "SELECT COUNT(*) c FROM restaurant_food_order_items WHERE food_item_id = ?");
        mysqli_stmt_bind_param($check, 'i', $id);
        mysqli_stmt_execute($check);
        $count = mysqli_fetch_assoc(mysqli_stmt_get_result($check))['c'];

        if ($count > 0) {
            flash_set('danger', "Cannot delete: this item has {$count} order record(s). Consider deactivating it instead.");
        } else {
            $imgStmt = mysqli_prepare($conn, "SELECT image_path FROM restaurant_food_items WHERE id = ?");
            mysqli_stmt_bind_param($imgStmt, 'i', $id);
            mysqli_stmt_execute($imgStmt);
            $imgRow = mysqli_fetch_assoc(mysqli_stmt_get_result($imgStmt));

            $stmt = mysqli_prepare($conn, "DELETE FROM restaurant_food_items WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            if (mysqli_stmt_execute($stmt)) {
                if ($imgRow && !empty($imgRow['image_path']) && strpos($imgRow['image_path'], FOOD_IMG_UPLOAD_URL) === 0) {
                    $old_file = __DIR__ . '/' . $imgRow['image_path'];
                    if (is_file($old_file)) @unlink($old_file);
                }
                flash_set('success', 'Food item deleted successfully.');
            } else {
                flash_set('danger', 'Failed to delete food item: ' . mysqli_error($conn));
            }
        }
    }

    header('Location: restaurant_food_items.php');
    exit;
}

$categories_result = mysqli_query($conn, "SELECT id, type FROM restaurant_food_categories WHERE is_active = 1 ORDER BY type ASC");
$categories_arr = [];
while ($c = mysqli_fetch_assoc($categories_result)) $categories_arr[] = $c;

$search = trim($_GET['q'] ?? '');
$filter_category = (int)($_GET['category'] ?? 0);

$sql = "SELECT i.*, c.type AS category_name
        FROM restaurant_food_items i
        JOIN restaurant_food_categories c ON c.id = i.food_category
        WHERE 1=1";
if ($search !== '') {
    $searchEsc = mysqli_real_escape_string($conn, $search);
    $sql .= " AND i.item_name LIKE '%{$searchEsc}%'";
}
if ($filter_category > 0) {
    $sql .= " AND i.food_category = {$filter_category}";
}
$sql .= " ORDER BY c.type ASC, i.item_name ASC";
$result = mysqli_query($conn, $sql);

$page_title = 'Food Items';
$active_menu = 'restaurant_food_items';
require __DIR__ . '/navbar.php';
?>

<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span><i class="bi bi-egg-fried me-1"></i> Food Items</span>
        <div class="d-flex flex-wrap gap-2">
            <form class="d-flex flex-wrap gap-2" method="GET">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search item..." value="<?= e($search) ?>" style="width:170px;">
                <select name="category" class="form-select form-select-sm" style="width:160px;" onchange="this.form.submit()">
                    <option value="0">All Categories</option>
                    <?php foreach ($categories_arr as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $filter_category === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['type']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
            </form>
            <button class="btn btn-brand btn-sm" data-bs-toggle="modal" data-bs-target="#itemModal" onclick="openCreateModal()">
                <i class="bi bi-plus-lg me-1"></i>Add Item
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Item Name</th>
                        <th>Category</th>
                        <th>Size</th>
                        <th>Price</th>
                        <th>Active</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($result) === 0): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No food items found.</td></tr>
                <?php else: while ($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td>
                            <?php if (!empty($row['image_path'])): ?>
                                <img src="<?= e($row['image_path']) ?>" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:8px;" onerror="this.style.display='none'">
                            <?php else: ?>
                                <div class="d-flex align-items-center justify-content-center text-muted" style="width:44px;height:44px;background:#f4f6f5;border-radius:8px;"><i class="bi bi-image"></i></div>
                            <?php endif; ?>
                        </td>
                        <td class="fw-semibold"><?= e($row['item_name']) ?></td>
                        <td><?= e($row['category_name']) ?></td>
                        <td><?= e($row['size']) ?: '—' ?></td>
                        <td>৳<?= number_format((float)$row['price'], 2) ?></td>
                        <td>
                            <?php if ((int)$row['is_active'] === 1): ?>
                                <span class="badge bg-success-subtle text-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-3">
                            <button class="btn btn-sm btn-outline-brand"
                                onclick='openEditModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger"
                                onclick="openDeleteModal(<?= (int)$row['id'] ?>, '<?= e(addslashes($row['item_name'])) ?>')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="itemModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="formId" value="">
            <input type="hidden" name="remove_image" id="formRemoveImage" value="0">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Food Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Item Name <span class="text-danger">*</span></label>
                        <input type="text" name="item_name" id="formItemName" class="form-control" required maxlength="150">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Category <span class="text-danger">*</span></label>
                        <select name="food_category" id="formFoodCategory" class="form-select" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($categories_arr as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"><?= e($c['type']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Size</label>
                        <input type="text" name="size" id="formSize" class="form-control" list="sizeOptions" maxlength="30" placeholder="e.g. 1:2, Half, Full">
                        <datalist id="sizeOptions">
                            <?php foreach ($size_options as $sz): ?>
                                <option value="<?= e($sz) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Price <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0" name="price" id="formPrice" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Item Image</label>
                        <input type="file" name="image_file" id="formImageFile" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif" onchange="onImageFileChange()">
                        <div class="form-text">JPG, PNG, WEBP or GIF, max 2MB.</div>
                        <div class="d-flex align-items-center gap-2 mt-2">
                            <img id="imagePreview" src="" alt="" style="display:none; width:70px; height:70px; object-fit:cover; border-radius:8px;" onerror="this.style.display='none'" onload="this.style.display='block'">
                            <button type="button" id="removeImageBtn" class="btn btn-sm btn-outline-danger" style="display:none;" onclick="markRemoveImage()">
                                <i class="bi bi-trash"></i> Remove image
                            </button>
                        </div>
                    </div>
                    <div class="col-6 d-flex align-items-end">
                        <div class="form-check">
                            <input type="checkbox" name="is_active" id="formIsActive" class="form-check-input" checked>
                            <label class="form-check-label small" for="formIsActive">Active</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-brand btn-sm">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId" value="">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete <strong id="deleteName"></strong>? This cannot be undone.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('modalTitle').innerText = 'Add Food Item';
    document.getElementById('formAction').value = 'create';
    document.getElementById('formId').value = '';
    document.getElementById('formItemName').value = '';
    document.getElementById('formFoodCategory').value = '';
    document.getElementById('formSize').value = '';
    document.getElementById('formPrice').value = '';
    document.getElementById('formImageFile').value = '';
    document.getElementById('formRemoveImage').value = '0';
    document.getElementById('imagePreview').style.display = 'none';
    document.getElementById('removeImageBtn').style.display = 'none';
    document.getElementById('formIsActive').checked = true;
}

function openEditModal(row) {
    document.getElementById('modalTitle').innerText = 'Edit Food Item';
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = row.id;
    document.getElementById('formItemName').value = row.item_name;
    document.getElementById('formFoodCategory').value = row.food_category;
    document.getElementById('formSize').value = row.size || '';
    document.getElementById('formPrice').value = row.price;
    document.getElementById('formImageFile').value = '';
    document.getElementById('formRemoveImage').value = '0';

    const img = document.getElementById('imagePreview');
    const removeBtn = document.getElementById('removeImageBtn');
    if (row.image_path) {
        img.src = row.image_path;
        img.style.display = 'block';
        removeBtn.style.display = 'inline-block';
    } else {
        img.style.display = 'none';
        removeBtn.style.display = 'none';
    }

    document.getElementById('formIsActive').checked = row.is_active == 1;
    new bootstrap.Modal(document.getElementById('itemModal')).show();
}

function onImageFileChange() {
    const fileInput = document.getElementById('formImageFile');
    const img = document.getElementById('imagePreview');
    const removeBtn = document.getElementById('removeImageBtn');
    const file = fileInput.files[0];
    if (file) {
        document.getElementById('formRemoveImage').value = '0';
        const reader = new FileReader();
        reader.onload = e => {
            img.src = e.target.result;
            img.style.display = 'block';
        };
        reader.readAsDataURL(file);
        removeBtn.style.display = 'inline-block';
    }
}

function markRemoveImage() {
    document.getElementById('formRemoveImage').value = '1';
    document.getElementById('formImageFile').value = '';
    document.getElementById('imagePreview').style.display = 'none';
    document.getElementById('removeImageBtn').style.display = 'none';
}

function openDeleteModal(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').innerText = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php require __DIR__ . '/footer.php'; ?>