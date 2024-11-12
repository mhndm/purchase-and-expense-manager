<?php
/*
Plugin Name: Purchase and Expense Manager
Plugin URI: https://wordpress.org/plugins/supplier-purchase-tracking
Description: Record and Monitor Expenses and Supplier Pricing for Each Product Across Multiple Vendors. When you add a transaction for a product sold in your store, the plugin updates its purchase price and adds the quantity to its stock. compatible with WooCommerce.
Version: 1.0.0
Author: codnloc
Author URI: http://codnloc.com/
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: supplier-purchase-tracking
Domain Path: /languages
*/

// منع الوصول المباشر للملف
if (!defined('ABSPATH')) {
    exit;
}


// Hook into admin_init to ensure all functions are available
add_action('admin_init', 'export_supplier_costs_to_csv');

function export_supplier_costs_to_csv() {
    global $wpdb, $wp_filesystem;

    // Check if the export CSV request is set
    if (isset($_GET['export_csv'])) {
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Initialize WP_Filesystem
        WP_Filesystem();

        $table_name = $wpdb->prefix . 'supplier_costs';
        $sort_column = isset($_GET['sort']) ? wp_unslash(sanitize_text_field($_GET['sort'])) : 'id';
        $sort_order = isset($_GET['order']) && wp_unslash(strtolower($_GET['order'])) === 'asc' ? 'ASC' : 'DESC';

        // Fetch all results (no pagination for CSV export)
        $csv_results = sup_pt_get_filtered_results($table_name, $sort_column, $sort_order, 100, 0);

        // Create a temporary file using PHP's tempnam
        $tmp_file = tempnam(sys_get_temp_dir(), 'supplier_purchases');

        // Check if the file was created
        if (!$tmp_file || !$wp_filesystem->put_contents($tmp_file, '')) {
            wp_die('Unable to create temporary file for CSV export.');
        }

        // Write UTF-8 BOM and header to the temporary file
        $csv_content = "\xEF\xBB\xBF";
        $csv_content .= implode(',', array('Item Name', 'Vendor Name', 'Purchase Price', 'Units Purchased', 'Date of Acquisition')) . "\r\n";

        // Add CSV data rows
        if ($csv_results) {
            foreach ($csv_results as $csv_row) {
                $product_name = get_the_title($csv_row->product_id);

                // Get the supplier user data
                $supplier_user = get_user_by('id', $csv_row->supplier_id);
                $supplier_name = $supplier_user ? $supplier_user->display_name : 'Unknown Supplier'; // Fallback if user not found

                $row = array(
                    esc_csv($product_name),
                    esc_csv($supplier_name),
                    esc_csv($csv_row->purchase_price),
                    esc_csv($csv_row->quantity),
                    esc_csv($csv_row->purchase_date)
                );

                // Use the defined esc_csv function to escape values
                $csv_content .= implode(',', array_map('esc_csv', $row)) . "\r\n";
            }
        }

        // Write the complete CSV content to the file
        $wp_filesystem->put_contents($tmp_file, $csv_content);

        // Set headers for the CSV download
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="supplier_purchases.csv"');

        // Use WP_Filesystem to read the file contents
        $csv_file_contents = $wp_filesystem->get_contents($tmp_file);
        if ($csv_file_contents !== false) {
            echo $csv_file_contents;
        } else {
            wp_die('Unable to read temporary file for CSV export.');
        }

        // Use wp_delete_file to remove the temporary file instead of unlink
        wp_delete_file($tmp_file);

        exit();
    }
}

// Define the esc_csv function to escape CSV values
function esc_csv($value) {
    // Escape value for CSV: enclose in quotes if it contains a comma, double quote, or newline
    if (is_numeric($value)) {
        return $value; // No need to escape numbers
    }
    return '"' . str_replace('"', '""', $value) . '"'; // Escape double quotes
}


// إنشاء جدول مخصص عند تفعيل الإضافة
function sup_pt_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'supplier_costs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        product_id bigint(20) NOT NULL,
        supplier_id bigint(20) NOT NULL,
        purchase_price decimal(10, 2) NOT NULL,
        purchase_date date NOT NULL,
        quantity int(11) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// حذف الجدول عند إلغاء تنصيب الإضافة
function sup_pt_delete_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'supplier_costs';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
}

// تنصيب وتفعيل الإضافة
register_activation_hook(__FILE__, 'sup_pt_create_table');

// إلغاء تنصيب الإضافة
register_uninstall_hook(__FILE__, 'sup_pt_delete_table');

// إضافة دور "Supplier" عند تنصيب الإضافة
function sup_pt_add_supplier_role() {
    add_role(
        'supplier',
        'Supplier',
        [
            'read' => true,  // المورد يمكنه قراءة
            'edit_posts' => false,
            'delete_posts' => false,
        ]
    );
}
add_action('init', 'sup_pt_add_supplier_role');

// إنشاء صفحة الإدخال المخصصة في لوحة التحكم
function sup_pt_add_admin_menu() {
    add_menu_page(
        'Supplier Expense Manager',
        'Supplier Expense',
        'manage_options',
        'supplier-cost-manager',
        'sup_pt_render_admin_page',
        'dashicons-analytics',
        20
    );
}
add_action('admin_menu', 'sup_pt_add_admin_menu');

// عرض صفحة الإدخال المخصصة
function sup_pt_render_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'supplier_costs';
    $items_per_page = 100; // عدد العناصر لكل صفحة


    // فلترة المعلومات
    $filter_supplier = isset($_GET['filter_supplier']) ? wp_unslash(sanitize_text_field($_GET['filter_supplier'])) : '';
    $filter_product = isset($_GET['filter_product']) ? wp_unslash(sanitize_text_field($_GET['filter_product'])) : '';
	$filter_date = isset($_GET['filter_date']) ? wp_unslash(sanitize_text_field($_GET['filter_date'])) : '';
	$filter_end_date = isset($_GET['filter_end_date']) ? wp_unslash(sanitize_text_field($_GET['filter_end_date'])) : '';
	
    // حساب الصفحات
    if ($filter_date) {
		$count_filter_conditions = $wpdb->prepare("purchase_date BETWEEN %s AND %s", $filter_date, $filter_end_date);
    }    

    $count_where_clause = !empty($count_filter_conditions) ? 'WHERE ' . $count_filter_conditions : '';

    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name $count_where_clause");
    $total_pages = ceil($total_items / $items_per_page);
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $items_per_page;

    // الترتيب الافتراضي
	

    $sort_column = isset($_GET['sort']) ? wp_unslash(sanitize_text_field($_GET['sort'])) : 'id';
    $sort_order = isset($_GET['order']) && wp_unslash(sanitize_text_field(strtolower($_GET['order']))) === 'asc' ? 'ASC' : 'DESC';

    // معالجة النموذج عند الإرسال
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $product_id = intval($_POST['product_id']);
        $supplier_name = wp_unslash(sanitize_text_field($_POST['supplier_name']));
        $purchase_price = floatval($_POST['purchase_price']);
        $purchase_date = sanitize_text_field($_POST['purchase_date']);
        $quantity = intval($_POST['quantity']);

        // حفظ المورد الجديد إذا لم يكن موجوداً مسبقاً
        $supplier_id = sup_pt_save_supplier($supplier_name);

        if ($supplier_id) {
            // إدخال البيانات إلى الجدول
            $insert_result = $wpdb->insert(
                $table_name,
                [
                    'product_id' => $product_id,
                    'supplier_id' => $supplier_id,
                    'purchase_price' => $purchase_price,
                    'purchase_date' => $purchase_date,
                    'quantity' => $quantity
                ]
            );

            if ($insert_result === false) {
                $error_message = $wpdb->last_error;
                echo '<div class="error"><p>Error: Could not insert purchase record. ' . esc_html($error_message) . '</p></div>';
            } else {
                // تحديث حقل _op_cost_price في WooCommerce
                update_post_meta($product_id, '_op_cost_price', $purchase_price);

                // تحديث المخزون _stock في WooCommerce
                $current_stock = get_post_meta($product_id, '_stock', true);
                $new_stock = intval($current_stock) + $quantity;
                update_post_meta($product_id, '_stock', $new_stock);

                echo '<div class="updated"><p>Purchase record added successfully.</p></div>';
            }
        } else {
            echo '<div class="error"><p>Error: Supplier could not be created.</p></div>';
        }
    }



    // عرض النموذج
    ?>
    <div class="wrap" style="direction:ltr">
        <h1>Supplier Expense Manager</h1>
        <form method="POST">
            <table class="form-table">
                <tr>
                    <th><label for="product_search">Item (by Name or Barcode)</label></th>
                    <td>
                        <input type="text" id="product_search" placeholder="Search product" autocomplete="off" required>
                        <div id="product_results"></div>
                    <!-- <th><label for="product_id">Product ID</label></th> -->
                    <input type="hidden" name="product_id" id="product_id" readonly required>
                    </td>
                </tr>
                <tr>
                    <th><label for="supplier_search">Vendor Name</label></th>
                    <td>
                        <input type="text" id="supplier_search" name="supplier_name" placeholder="Search or Add Supplier" autocomplete="off" required>
                        <div id="supplier_results"></div>
                    </td>
                </tr>
                <tr>
                    <th><label for="purchase_price">Unit Cost</label></th>
                    <td><input type="number" step="0.01" name="purchase_price" id="purchase_price" min="0" required></td>
                </tr>
                <tr>
                    <th><label for="purchase_date">Date of Acquisition</label></th>
                    <td><input type="date" name="purchase_date" id="purchase_date" data-date-format="DD MMMM YYYY" required></td>
                </tr>
                <tr>
                    <th><label for="quantity">Units Purchased</label></th>
                    <td><input type="number" name="quantity" id="quantity" min="0" required></td>
                </tr>
            </table>
            <input type="submit" value="Add Purchase" class="button button-primary">
        </form>
			<br/>
        <h2>Transactions</h2>

        <!-- نموذج الفلترة -->
        <form method="GET">
            <input type="hidden" name="page" value="supplier-cost-manager">
            <input type="text" name="filter_supplier" placeholder="Filter by Supplier" value="<?php echo esc_attr($filter_supplier); ?>">
            <input type="text" name="filter_product" placeholder="Filter by Product" value="<?php echo esc_attr($filter_product); ?>">
            <input type="date" name="filter_date" value="<?php echo esc_attr($filter_date); ?>"> - 
            <input type="date" name="filter_end_date" value="<?php echo esc_attr($filter_end_date); ?>">
            
			<input type="submit" value="Filter" class="button">
			
			<?php  $reset_url = admin_url('admin.php?page=supplier-cost-manager'); ?>
			<a href="<?php echo esc_html($reset_url);?>" style="text-decoration: none; display: inline-block; padding: 7px;">Reset result</a>
			
			<input type="submit" name="export_csv" value="Export CSV" Style="float: right;"> 
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><a href="?page=supplier-cost-manager&sort=id&order=<?php echo ($sort_column === 'id' && $sort_order === 'ASC') ? 'desc' : 'asc'; ?>">ID</a></th>
                    <!-- <th><a href="?page=supplier-cost-manager&sort=product_id&order=<?php echo ($sort_column === 'product_id' && $sort_order === 'ASC') ? 'desc' : 'asc'; ?>">Product ID</a></th> -->
                    <th><a href="?page=supplier-cost-manager&sort=product_id&order=<?php echo ($sort_column === 'product_id' && $sort_order === 'ASC') ? 'desc' : 'asc'; ?>">Item Name</th>
                    <th><a href="?page=supplier-cost-manager&sort=supplier_id&order=<?php echo ($sort_column === 'supplier_id' && $sort_order === 'ASC') ? 'desc' : 'asc'; ?>">Supplier Name</a></th>
                    <th><a href="?page=supplier-cost-manager&sort=quantity&order=<?php echo ($sort_column === 'quantity' && $sort_order === 'ASC') ? 'desc' : 'asc'; ?>">Quantity</a></th>
				  <th><a href="?page=supplier-cost-manager&sort=purchase_price&order=<?php echo ($sort_column === 'purchase_price' && $sort_order === 'ASC') ? 'desc' : 'asc'; ?>">Purchase Price</a></th>
                    <th><a href="?page=supplier-cost-manager&sort=purchase_date&order=<?php echo ($sort_column === 'purchase_date' && $sort_order === 'ASC') ? 'desc' : 'asc'; ?>">Date of Acquisition</a></th>
					 <?php echo '<th>Action</th>'; ?>
				</tr>
            </thead>
            <tbody>
                <?php
                // جلب البيانات
				$results = sup_pt_get_filtered_results($table_name, $sort_column, $sort_order, $items_per_page, $offset);
				$total = 0;
                if ($results) {
                    foreach ($results as $row) {
                        $product_name = get_the_title($row->product_id);
                        $supplier_name = get_the_author_meta('display_name', $row->supplier_id);
						$quantity = esc_html($row->quantity);
						$purchase_price = esc_html($row->purchase_price);
                        echo '<tr>';
                        echo '<td>' . esc_html($row->id) . '</td>';
                       // echo '<td>' . esc_html($row->product_id) . '</td>';
                        echo '<td>' . esc_html($product_name) . '</td>';
                        echo '<td>' . esc_html($supplier_name) . '</td>';
                        echo '<td>' . esc_html($quantity) . '</td>';
                        echo '<td>' . esc_html($purchase_price) . '</td>';
						$date = date_create(esc_html($row->purchase_date));
                        echo '<td>' . esc_html(date_format($date,"d/m/Y")) . '</td>';
						echo '<td><a href="?page=supplier-cost-manager&action=delete&purchase_id=' . intval($row->id) . '" onclick="return confirm(\'Are you sure you want to delete this purchase?\');">Delete</a></td>'; // Delete link
                        echo '</tr>';
						$total += ($purchase_price * $quantity);
                    }
                } else {
                    echo '<tr><td colspan="7">No purchases found.</td></tr>';
                }
				$all_result = sup_pt_get_filtered_results($table_name, $sort_column, $sort_order, PHP_INT_MAX, 0);
				$all_pages_total = 0;
                if ($all_result) {
                    foreach ($all_result as $row) {
						$quantity = esc_html($row->quantity);
						$purchase_price = esc_html($row->purchase_price);
						$all_pages_total += ($purchase_price * $quantity);
                    }
                }
				
                ?>
            </tbody>
			<tfoot>
			<tr>

			<td><b>All pages total: <?php echo esc_html($all_pages_total); ?></b></td>
			<td>
			Current page total: <?php echo esc_html($total); ?>
			</td>
			<td></td><td></td><td></td><td></td><td></td>	
			</tr>
			</tfoot>
        </table>
        <!-- Pagination -->
        <?php if ($total_pages > 1) : ?>
            <div class="pagination">
                <?php
                $pagination_args = [
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'current' => $current_page,
                    'total' => $total_pages,
                ];
                echo esc_html(paginate_links($pagination_args));
                ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // بحث المنتجات
        $('#product_search').on('input', function() {
            var search = $(this).val();

            if (search.length > 2) {
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'sup_pt_search_products',
                        search: search
                    },
                    success: function(response) {
                        $('#product_results').html(response);
                    }
                });
            }
        });

        // عند اختيار المنتج من القائمة
        $(document).on('click', '.product-result-item', function() {
            var productId = $(this).data('id');
            var productName = $(this).text();

            $('#product_id').val(productId);
            $('#product_search').val(productName);
            $('#product_results').empty();
        });

        // بحث الموردين
        $('#supplier_search').on('input', function() {
            var search = $(this).val();

            if (search.length > 2) {
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'sup_pt_search_suppliers',
                        search: search
                    },
                    success: function(response) {
                        $('#supplier_results').html(response);
                    }
                });
            }
        });

        // عند اختيار المورد من القائمة
        $(document).on('click', '.supplier-result-item', function() {
            var supplierId = $(this).data('id');
            var supplierName = $(this).text();

            $('#supplier_id').val(supplierId);
            $('#supplier_search').val(supplierName);
            $('#supplier_results').empty();
        });
    });
    </script>
    <?php
}


// Reusable function to fetch filtered results
function sup_pt_get_filtered_results($table_name, $sort_column, $sort_order, $items_per_page, $offset) {
    global $wpdb;

    $filter_supplier = isset($_GET['filter_supplier']) ? sanitize_text_field($_GET['filter_supplier']) : '';
    $filter_product = isset($_GET['filter_product']) ? sanitize_text_field($_GET['filter_product']) : '';
    $filter_date = isset($_GET['filter_date']) ? sanitize_text_field($_GET['filter_date']) : '';
    $filter_end_date = isset($_GET['filter_end_date']) ? sanitize_text_field($_GET['filter_end_date']) : '';

    $filter_conditions = [];
    if ($filter_supplier) {
        $filter_conditions[] = $wpdb->prepare("supplier_id IN (SELECT ID FROM {$wpdb->prefix}users WHERE display_name LIKE %s)", '%' . $wpdb->esc_like($filter_supplier) . '%');
    }
    if ($filter_product) {
        $filter_conditions[] = $wpdb->prepare("product_id IN (SELECT ID FROM {$wpdb->prefix}posts WHERE post_title LIKE %s)", '%' . $wpdb->esc_like($filter_product) . '%');
    }
    if ($filter_date) {
		$filter_conditions[] = $wpdb->prepare("purchase_date BETWEEN %s AND %s", $filter_date, $filter_end_date);
    }    

    $where_clause = !empty($filter_conditions) ? 'WHERE ' . implode(' AND ', $filter_conditions) : '';

    // Fetch results
    return $wpdb->get_results("
        SELECT * FROM $table_name
        $where_clause
        ORDER BY $sort_column $sort_order
        LIMIT $items_per_page OFFSET $offset
    ");
}


/*
// Inside sup_pt_render_admin_page(), replace the query with the function call:

$csv_results = $results; // To make it available for CSV export
*/

// دالة البحث عن المنتجات باستخدام AJAX
function sup_pt_search_products() {
    global $wpdb;

    $search_term = sanitize_text_field($_POST['search']);

    $products = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title FROM {$wpdb->prefix}posts WHERE post_type = 'product' AND post_title LIKE %s",
        '%' . $wpdb->esc_like($search_term) . '%'
    ));

    if ($products) {
        foreach ($products as $product) {
            echo '<div class="product-result-item" data-id="' . esc_html($product->ID) . '">' . esc_html($product->post_title) . '</div>';
        }
    } else {
        echo '<div>No products found</div>';
    }

    wp_die(); // إنهاء الاتصال بـ AJAX
}
add_action('wp_ajax_sup_pt_search_products', 'sup_pt_search_products');

// دالة البحث عن الموردين باستخدام AJAX
function sup_pt_search_suppliers() {
    global $wpdb;

    $search_term = sanitize_text_field($_POST['search']);

    $suppliers = get_users([
        'search' => '*' . esc_attr($search_term) . '*',
        'role' => 'supplier',
    ]);

    if ($suppliers) {
        foreach ($suppliers as $supplier) {
            echo '<div class="supplier-result-item" data-id="' . esc_html($supplier->ID) . '">' . esc_html($supplier->display_name) . '</div>';
        }
    } else {
        echo '<div>No suppliers found, we will add it as new one</div>';
    }

    wp_die(); // إنهاء الاتصال بـ AJAX
}
add_action('wp_ajax_sup_pt_search_suppliers', 'sup_pt_search_suppliers');

// حفظ المورد كمستخدم جديد

function sup_pt_save_supplier($supplier_name) {
    $supplier_name = trim($supplier_name); // إزالة المسافات الزائدة

    // التحقق من أن اسم المورد غير فارغ
    if (empty($supplier_name)) {
        echo '<div class="error"><p>Error: Supplier name cannot be empty.</p></div>';
        return false;
    }

    // البحث عن مستخدم موجود باستخدام الحقل المخصص
    global $wpdb;
    $table_name = $wpdb->prefix . 'users';
    $supplier_id = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM $table_name WHERE ID IN (
            SELECT user_id FROM {$wpdb->prefix}usermeta WHERE meta_key = 'first_name' AND meta_value = %s
        )",
        $supplier_name
    ));

    if (!$supplier_id) {
        // إنشاء مستخدم جديد في مجموعة الموردين
        $user_id = wp_insert_user([
            'user_login' => wp_generate_password(), // توليد اسم مستخدم عشوائي
            'user_pass' => wp_generate_password(), // توليد كلمة مرور عشوائية
			'display_name' => $supplier_name, // استخدام user_nicename لتخزين اسم المورد
            'role' => 'supplier',
        ]);

        if (is_wp_error($user_id)) {
            // عرض رسالة خطأ إذا فشل إنشاء المستخدم
            echo '<div class="error"><p>Error: ' . esc_html($user_id->get_error_message()) . '</p></div>';
            return false;
        } else {
            // حفظ اسم المورد في حقل first_name
            update_user_meta($user_id, 'first_name', $supplier_name);
            return $user_id;
        }
    } else {
        return $supplier_id;
    }
}

// Handle deletion of purchases
function sup_pt_handle_deletion() {
    global $wpdb;

    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['purchase_id'])) {
        $purchase_id = intval($_GET['purchase_id']);
        
        // Delete the record from the database
        $table_name = $wpdb->prefix . 'supplier_costs';
        $result = $wpdb->delete($table_name, ['id' => $purchase_id]);

        if ($result === false) {
            echo '<div class="error"><p>Error: Could not delete the purchase record.</p></div>';
        } else {
            echo '<div class="updated"><p>Purchase has been deleted successfully.</p></div>';
        }

        // Redirect to avoid resubmission
        wp_redirect(admin_url('admin.php?page=supplier-cost-manager')); 
        exit;
    }
}
add_action('admin_init', 'sup_pt_handle_deletion');


function sup_pt_enqueue_pagination_styles() {
	
echo '<style>

/* Basic styling for pagination in admin panel */
.pagination {
    display: flex;
    justify-content: center;
    margin: 20px 0;
}

.pagination .current {
    display: inline-block;
    padding: 8px 12px;
    margin: 0 4px;
    text-decoration: none;
    color: #333;
    border: 1px solid #ddd;
    border-radius: 5px;
	background-color: #007bff;
    color: #fff;
    border-color: #007bff;
}

.pagination a {
    display: inline-block;
    padding: 8px 12px;
    margin: 0 4px;
    text-decoration: none;
    color: #333;
    border: 1px solid #ddd;
    border-radius: 5px;
    transition: background-color 0.3s, color 0.3s;
}

.pagination a:hover {
    background-color: #007bff;
    color: #fff;
    border-color: #007bff;
}

.pagination a.active,
.pagination a.current {
    background-color: #007bff;
    color: #fff;
    border-color: #007bff;
    font-weight: bold;
    cursor: default;
}

.pagination a.current {
    font-weight: bold;
}

/* Style for delete link */
.table .delete-link {
    color: #d9534f; /* Red color for delete */
    text-decoration: none;
    font-weight: bold;
}

.table .delete-link:hover {
    text-decoration: underline;
}


.product-result-item,
.supplier-result-item {
    display: flex;
    align-items: center;
    padding: 10px;
    margin-bottom: 10px;
    border: 1px solid #ddd;
    border-radius: 5px;
    background-color: #fff;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    transition: background-color 0.3s, box-shadow 0.3s;
	width: 200px;
}

/* Hover effects */
.product-result-item:hover, 
.supplier-result-item:hover {
    background-color: #f5f5f5;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.widefat td{
	font-size: 15px;
	
}
</style>';

}
add_action('admin_head', 'sup_pt_enqueue_pagination_styles');


