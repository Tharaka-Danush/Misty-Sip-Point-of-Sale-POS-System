<?php
require_once __DIR__.'/../includes/bootstrap.php'; require_admin(); $pdo=db();
function fixed_product_size(string $categoryName): ?string {
  $name=strtolower(trim($categoryName));
  if(in_array($name,['coffee & hot drinks','hot drinks','sundae','ice cream','fruit salad'],true))return 'M';
  if($name==='fruit tea')return 'L';
  return null;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();$action=$_POST['action']??'save';$id=(int)($_POST['id']??0);$redirect='products.php'.(($action==='toggle'||$action==='stock')&&$id?'#product-'.$id:(strpos($action,'category_')===0?'#manage-categories':''));
  try{
    if($action==='category_save'){$name=trim($_POST['name']??'');$color=$_POST['color']??'#0f5132';if($name==='')throw new RuntimeException('Category name is required.');$s=$pdo->prepare('INSERT INTO categories(name,color) VALUES(?,?)');$s->execute([$name,$color]);$_SESSION['flash']='Category saved.';}
    elseif($action==='category_delete'){$categoryId=(int)($_POST['category_id']??0);if($categoryId<1)throw new RuntimeException('Invalid category.');$count=$pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id=?');$count->execute([$categoryId]);if((int)$count->fetchColumn()>0)throw new RuntimeException('This category has products and cannot be deleted.');$s=$pdo->prepare('DELETE FROM categories WHERE id=?');$s->execute([$categoryId]);$_SESSION['flash']='Empty category deleted.';}
    elseif($action==='toggle'&&$id){$s=$pdo->prepare('UPDATE products SET is_active=1-is_active WHERE id=?');$s->execute([$id]);}
    elseif($action==='stock'&&$id){$qty=(int)($_POST['qty']??0);if($qty!=0){$pn=$pdo->prepare('SELECT name,stock_qty FROM products WHERE id=?');$pn->execute([$id]);$pr=$pn->fetch();$pdo->beginTransaction();$s=$pdo->prepare('UPDATE products SET stock_qty=stock_qty+? WHERE id=? AND stock_qty+?>=0');$s->execute([$qty,$id,$qty]);if(!$s->rowCount())throw new RuntimeException('Stock cannot be reduced below zero.');$s=$pdo->prepare("INSERT INTO stock_movements(product_id,user_id,movement_type,qty,note) VALUES(?,?,'adjustment',?,'Manual stock adjustment')");$s->execute([$id,auth_user()['id'],$qty]);$pdo->commit();log_activity('Stock Adjusted','stock','Product: '.($pr['name']??('#'.$id)).' • Change: '.($qty>0?'+':'').$qty.' • New Stock: '.((float)($pr['stock_qty']??0)+$qty),$id);}}
    else{$categoryId=(int)$_POST['category_id'];$catStmt=$pdo->prepare('SELECT name FROM categories WHERE id=?');$catStmt->execute([$categoryId]);$categoryName=(string)$catStmt->fetchColumn();if($categoryName==='')throw new RuntimeException('Invalid category.');$size=(string)($_POST['size']??'');$fixedSize=fixed_product_size($categoryName);if($fixedSize!==null)$size=$fixedSize;if(!in_array($size,['S','M','L'],true))throw new RuntimeException('Invalid product size.');$name=trim($_POST['name']);if($name==='')throw new RuntimeException('Product name is required.');$dupe=$pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id=? AND name=? AND size=?');$dupe->execute([$categoryId,$name,$size]);if((int)$dupe->fetchColumn()>0)throw new RuntimeException('This product and size already exists.');$image=save_product_image($_FILES['image']??[],default_product_image($categoryId));$s=$pdo->prepare('INSERT INTO products(category_id,name,size,price,stock_qty,low_stock_level,image_path) VALUES(?,?,?,?,?,?,?)');$s->execute([$categoryId,$name,$size,(float)$_POST['price'],(float)$_POST['stock_qty'],(float)$_POST['low_stock_level'],$image]);}
    if($action==='save')$_SESSION['flash']='Product updated.';
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$_SESSION['flash']='Error: '.$e->getMessage();}
  header('Location: '.$redirect);exit;
}
$cats=$pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();$categoryRows=$pdo->query('SELECT c.*,COUNT(p.id) product_count FROM categories c LEFT JOIN products p ON p.category_id=c.id GROUP BY c.id ORDER BY c.name')->fetchAll();$rows=$pdo->query("SELECT p.*,c.name category,c.color category_color FROM products p JOIN categories c ON c.id=p.category_id WHERE LOWER(c.name) NOT IN ('ice cream','fruit tea','sundae','hot drinks','coffee & hot drinks','fruit salad') OR (LOWER(c.name)='fruit tea' AND p.size='L') OR (LOWER(c.name) IN ('ice cream','sundae','hot drinks','coffee & hot drinks','fruit salad') AND p.size='M') ORDER BY c.id ASC,p.name ASC,FIELD(p.size,'S','M','L','U') ASC,p.id ASC")->fetchAll();$variantCounts=[];foreach($rows as $r){$key=(int)$r['category_id'].'|'.mb_strtolower((string)$r['name']);$variantCounts[$key]=($variantCounts[$key]??0)+1;}usort($rows,function($a,$b)use($variantCounts){$ka=(int)$a['category_id'].'|'.mb_strtolower((string)$a['name']);$kb=(int)$b['category_id'].'|'.mb_strtolower((string)$b['name']);$singleA=(($variantCounts[$ka]??0)===1)?0:1;$singleB=(($variantCounts[$kb]??0)===1)?0:1;if($singleA!==$singleB)return $singleA<=>$singleB;$cat=((int)$a['category_id'])<=>((int)$b['category_id']);if($cat!==0)return $cat;$name=strcasecmp((string)$a['name'],(string)$b['name']);if($name!==0)return $name;$order=['S'=>0,'M'=>1,'L'=>2,'U'=>3];$size=($order[$a['size']]??9)<=>($order[$b['size']]??9);return $size!==0?$size:((int)$a['id']<=> (int)$b['id']);});$pageTitle='Products & Stock';$pageSubtitle='Manage products, pricing and inventory';include __DIR__.'/../includes/header.php';
?>
<style>
/* Page-local fallback prevents stale Chrome/XAMPP CSS from breaking this layout. */
.category-manager{padding:16px 18px!important}.category-manager-head{display:flex!important;align-items:center;justify-content:space-between;gap:18px;margin-bottom:14px}.category-manager-head h2{margin:0 0 3px}.category-manager-head small{color:#71827a}.category-manager-actions{display:flex;align-items:center;gap:9px}.category-collapse{width:40px;height:40px;border:1px solid #d4e4dc;border-radius:10px;background:#fff;color:#075b3b;font-size:21px;cursor:pointer}.category-inline-grid{display:grid!important;grid-template-columns:repeat(4,minmax(170px,1fr));border:1px solid #dfeae5;border-radius:13px;overflow:hidden}.category-inline-item{display:grid!important;grid-template-columns:30px minmax(0,1fr) auto;align-items:center;gap:9px;min-width:0;padding:11px 13px;border-right:1px solid #e6eeea;border-bottom:1px solid #e6eeea;background:#fff}.category-inline-item:nth-child(4n){border-right:0}.category-inline-item .swatch{display:block;width:24px;height:24px;border-radius:7px}.category-inline-item div{min-width:0}.category-inline-item strong,.category-inline-item small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.category-inline-item strong{font-size:12px}.category-inline-item small{margin-top:2px;color:#71827a;font-size:9px}.category-inline-item form button{border:0;background:transparent;color:#b42318;font-size:18px;cursor:pointer}.category-add-form{display:grid;grid-template-columns:minmax(220px,1fr) 150px auto auto;align-items:end;gap:10px;margin-bottom:13px;padding:12px;border:1px solid #dce9e2;border-radius:12px;background:#f7fbf9}.category-add-form[hidden]{display:none!important}.product-tools-panel{padding:15px 18px!important}.product-admin-toolbar{display:grid!important;grid-template-columns:minmax(220px,320px) minmax(180px,240px) auto;justify-content:start;gap:12px;align-items:center}.product-admin-toolbar input,.product-admin-toolbar select{margin:0!important;height:42px}.product-tools-panel .add-box{margin-top:14px}.product-tools-panel .add-box:not([open]){display:none}@media(max-width:1100px){.category-inline-grid{grid-template-columns:repeat(2,minmax(170px,1fr))}.category-inline-item:nth-child(4n){border-right:1px solid #e6eeea}.category-inline-item:nth-child(2n){border-right:0}}@media(max-width:720px){.category-manager-head{align-items:flex-start}.category-manager-actions{flex-wrap:wrap;justify-content:flex-end}.category-inline-grid{grid-template-columns:1fr}.category-inline-item{border-right:0!important}.category-add-form,.product-admin-toolbar{grid-template-columns:1fr!important}}
</style>
<style>
@media(min-width:1101px){.category-inline-grid{grid-template-columns:repeat(5,minmax(150px,1fr))!important}.category-inline-item:nth-child(4n){border-right:1px solid #e6eeea!important}.category-inline-item:nth-child(5n){border-right:0!important}}
.products-stock-panel{padding:0!important;overflow:visible}.products-stock-panel .table-wrap{overflow:visible;padding:0 18px 18px}.modern-products-table{width:100%;border:1px solid #dfe9e4;border-collapse:separate;border-spacing:0;border-radius:12px;background:#fff}.modern-products-table th{padding:13px 12px;background:#fbfdfc;border-bottom:1px solid #dfe9e4;color:#60736a;font-size:11px;font-weight:800;text-transform:uppercase;text-align:left}.modern-products-table td{padding:10px 12px;border-bottom:1px solid #e7eeea;background:#fff;vertical-align:middle;font-size:13px}.modern-products-table tbody tr:last-child td{border-bottom:0}.modern-products-table tbody tr:hover td{background:#fbfefc}.product-cell{display:flex;align-items:center;gap:12px;min-width:230px}.product-cell .product-thumb{width:48px;height:48px;border-radius:8px;object-fit:cover}.product-cell strong,.product-cell small{display:block}.product-cell small{margin-top:3px;color:#819087;font-size:10px}.category-cell{display:inline-flex;align-items:center;gap:9px;white-space:nowrap}.category-dot{display:inline-block;width:22px;height:22px;border-radius:7px}.stock-number-in{color:#087943}.stock-number-low{color:#e66a00}.stock-number-out,.stock-number-inactive{color:#e32636}.stock-pill{display:inline-flex;justify-content:center;min-width:86px;padding:7px 12px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap}.stock-pill-in{background:#dff5e9;color:#087943}.stock-pill-low{background:#fff0df;color:#db6500}.stock-pill-out{background:#ffe5e8;color:#dc2336}.stock-pill-inactive{background:#ecefed;color:#5f6a64}.product-actions{display:flex;align-items:center;justify-content:flex-end;gap:12px;min-width:150px}.edit-product-btn{display:inline-flex;gap:6px;padding:7px 13px;border:1px solid #d6e3dc;border-radius:8px;background:#fff;color:#10281d;font-weight:800;text-decoration:none}.row-actions{position:relative}.row-actions summary{display:flex;width:36px;height:34px;align-items:center;justify-content:center;border-radius:8px;cursor:pointer;list-style:none;font-weight:900;letter-spacing:2px}.row-actions summary::-webkit-details-marker{display:none}.row-actions-menu{position:absolute;z-index:20;right:0;top:39px;width:190px;padding:10px;border:1px solid #d9e5df;border-radius:10px;background:#fff;box-shadow:0 12px 30px rgba(11,72,49,.16)}.row-actions-menu form+form{margin-top:8px}.row-actions-menu .stock-adjust-form{display:grid;grid-template-columns:55px 1fr;gap:6px}.row-actions-menu .mini{width:55px;min-width:0}.row-actions-menu .action-toggle{width:100%;padding:8px;border:0;border-radius:7px;background:#f2f7f4;color:#075b3b;font-weight:800}.stock-inactive td{opacity:.72}@media(max-width:1050px){.products-stock-panel .table-wrap{overflow-x:auto}.modern-products-table{min-width:900px}}
</style>
<style>
.modern-products-table th:last-child{text-align:center!important}.product-actions{justify-content:center!important}.category-manager{padding:10px 14px!important}.category-manager-head{margin-bottom:7px!important}.category-manager-head small{font-size:11px}.category-title{display:flex;align-items:center;gap:6px}.category-title h2{font-size:19px}.category-title .category-collapse{width:auto;height:auto;padding:2px;border:0;border-radius:4px;background:transparent;color:#075b3b;font-size:13px;line-height:1}.category-title .category-collapse:hover{background:#edf7f2}.category-inline-item{padding:7px 10px!important;grid-template-columns:24px minmax(0,1fr) auto!important}.category-inline-item .swatch{width:20px!important;height:20px!important;border-radius:6px}.category-inline-item strong{font-size:11px!important}.category-inline-item small{font-size:8px!important;margin-top:1px!important}.category-manager-footer{display:flex;justify-content:flex-start;margin-top:7px}.category-manager-footer .btn{min-height:34px;padding:7px 16px}.category-add-form{grid-template-columns:minmax(260px,1fr) 110px 150px 110px!important;gap:8px!important;margin:7px 0 0!important;padding:9px!important}.category-add-form label{font-size:11px}.category-add-form input{height:38px;margin-top:3px}.category-add-form input[type=color]{width:100%;padding:4px}.category-add-form .btn{height:38px;margin:0}@media(max-width:850px){.category-add-form{grid-template-columns:1fr!important}}
</style>
<style>
.category-manager-footer[hidden]{display:none!important}
</style>
<section class="panel category-manager" id="manage-categories">
  <div class="category-manager-head"><div><div class="category-title"><button class="category-collapse" type="button" id="toggleCategories" aria-expanded="false" aria-label="Expand categories">▶</button><h2>Manage Categories</h2></div><small>Organize the categories used by your products</small></div></div>
  <div id="categoryManagerBody" hidden>
    <div class="category-inline-grid"><?php foreach($categoryRows as $c):?><article class="category-inline-item"><i class="swatch" style="background:<?=e($c['color'])?>"></i><div><strong><?=e($c['name'])?></strong><small><?=e($c['product_count'])?> products</small></div><?php if((int)$c['product_count']===0):?><form method="post" onsubmit="return confirm('Delete this empty category?');"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="category_delete"><input type="hidden" name="category_id" value="<?=e($c['id'])?>"><button type="submit" title="Delete empty category">×</button></form><?php endif;?></article><?php endforeach;?></div>
    <div class="category-manager-footer" id="categoryAddTrigger"><button class="btn primary" type="button" id="showCategoryForm">＋ Add Category</button></div>
    <form class="category-add-form" id="categoryAddForm" method="post" autocomplete="off" hidden><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="category_save"><label>Category Name<input name="name" placeholder="Enter category name" required></label><label>Colour<input type="color" name="color" value="#ef476f"></label><button class="btn primary" type="submit">Save Category</button><button class="btn" type="button" id="cancelCategoryForm">Cancel</button></form>
  </div>
</section>
<section class="panel add-product-manager" id="add-product-panel"><div class="add-product-manager-head"><div><div class="category-title"><button class="category-collapse" type="button" id="toggleProductForm" aria-expanded="false" aria-label="Expand add product form">▶</button><h2>Add New Product</h2></div><small>Create a new product and set its opening stock</small></div></div><div id="addProductBody" hidden><form class="form-grid add-product-form" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><label>Category<select name="category_id" id="newProductCategory" required><?php foreach($cats as $c):?><option value="<?=e($c['id'])?>" data-fixed-size="<?=e(fixed_product_size($c['name'])??'')?>"><?=e($c['name'])?></option><?php endforeach;?></select></label><label>Product Name<input name="name" required></label><label>Size<select name="size" id="newProductSize"><option>S</option><option selected>M</option><option>L</option></select></label><label>Price<input type="number" name="price" step=".01" min="0" required></label><label>Opening Stock<input type="number" name="stock_qty" step="1" min="0" inputmode="numeric" required></label><label>Low Stock Level<input type="number" name="low_stock_level" step="1" min="0" inputmode="numeric" value="5" required></label><label>Product Photo<input type="file" name="image" accept="image/jpeg,image/png,image/webp"><small>JPG/PNG/WEBP, max 3 MB</small></label><button class="btn primary">Save Product</button></form></div></section>
<?php
$productGroups=[];
foreach($rows as $productRow){
  $groupKey=$productRow['category'].'|'.$productRow['name'];
  if(!isset($productGroups[$groupKey]))$productGroups[$groupKey]=[];
  $productGroups[$groupKey][]=$productRow;
}
?>
<style>
.modern-products-table .group-cell{vertical-align:middle;background:#fff}
.modern-products-table tbody td{border-bottom:0!important}
.modern-products-table .group-start td{border-top:1px solid #e7eeea!important;padding-top:10px!important;padding-bottom:10px!important}
.modern-products-table tbody tr:first-child td{border-top:0!important}
.modern-products-table .variant-divider td{border-top:1px solid #e7eeea!important;padding-top:10px!important;padding-bottom:10px!important}
.modern-products-table tbody tr.product-row{height:54px}
.modern-products-table tbody tr.product-row>td:not(.group-cell){height:54px;box-sizing:border-box}
/* Requested only: align Category and Size columns */
.modern-products-table th:nth-child(2),
.modern-products-table th:nth-child(3),
.modern-products-table .category-group-cell,
.modern-products-table .size-cell{text-align:center!important}
.modern-products-table .category-group-cell .category-cell{display:flex!important;justify-content:center!important;width:100%!important}
.modern-products-table .size-cell{vertical-align:middle!important}

.stock-adjust-inline{display:flex;align-items:center;justify-content:center;gap:6px}
.stock-adjust-inline .adjust-choice{width:72px;height:34px;margin:0;padding:5px 7px;border:1px solid #d6e3dc;border-radius:8px;background:#fff;color:#16382a;font-weight:700;text-align:center}
.stock-adjust-inline .apply-stock{height:34px;padding:0 12px;border:0;border-radius:8px;background:#087a51;color:#fff;font-size:11px;font-weight:800;cursor:pointer}
.stock-adjust-inline .apply-stock:hover{background:#056541}
.product-actions{min-width:185px!important;justify-content:center!important;gap:7px!important}
.product-actions form{margin:0}
.product-actions .edit-product-btn{height:34px;align-items:center;padding:7px 11px;background:#2583f7;color:#fff;border-color:#2583f7}.product-actions .edit-product-btn:hover{background:#126fe0;border-color:#126fe0;color:#fff}
.action-toggle-direct{min-width:92px;padding:8px 12px;border:0;border-radius:9px;font-weight:800;cursor:pointer}
.action-toggle-direct.is-active{background:#16a765;color:#fff}
.action-toggle-direct.is-inactive{background:#e4e9e6;color:#65726b}
.stock-inactive .group-cell,.stock-inactive td{opacity:.72}
@media(max-width:1150px){.modern-products-table{min-width:1040px}}
.add-product-manager{padding:10px 14px!important}
.add-product-manager-head{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:18px!important}
.add-product-manager-head h2{margin:0!important;font-size:19px!important}
.add-product-manager-head small{display:block;margin-top:4px;color:#71827a;font-size:11px}
.add-product-manager #addProductBody{margin-top:10px}
.add-product-manager #addProductBody[hidden]{display:none!important}
.add-product-manager .add-product-form{margin:0!important;padding:14px!important;border:1px solid #dce9e2!important;border-radius:13px!important;background:#f7fbf9!important}
.product-inventory-panel{margin-top:0!important;padding:14px 18px 18px!important;background:#fff!important;border:1px solid #dce7e1!important;border-radius:18px!important;box-shadow:0 7px 22px rgba(11,59,37,.06)!important;overflow:visible!important}
.product-inventory-panel .product-admin-toolbar{display:grid!important;grid-template-columns:minmax(220px,320px) minmax(180px,240px)!important;align-items:center!important;justify-content:start!important;gap:12px!important;margin:0 0 14px!important}
.product-inventory-panel .product-admin-toolbar input,.product-inventory-panel .product-admin-toolbar select{height:42px!important;margin:0!important}
.product-inventory-panel .table-wrap{padding:0!important;overflow:visible!important}
.product-inventory-panel .modern-products-table th:nth-child(n+2),
.product-inventory-panel .modern-products-table td:nth-child(n+2){text-align:center!important;vertical-align:middle!important}
.product-inventory-panel .modern-products-table .category-cell{justify-content:center!important}
.product-inventory-panel .modern-products-table .stock-adjust-inline,
.product-inventory-panel .modern-products-table .product-actions{justify-content:center!important}
@media(max-width:900px){.product-inventory-panel .product-admin-toolbar,.add-product-manager .form-grid{grid-template-columns:1fr!important}.product-inventory-panel .table-wrap{overflow-x:auto!important}}
</style>
<section class="panel products-stock-panel product-inventory-panel"><div class="product-admin-toolbar"><input id="productSearch" type="search" placeholder="Search products..."><select id="productCategoryFilter"><option value="">All Categories</option><?php foreach($cats as $c):?><option value="<?=e(strtolower($c['name']))?>"><?=e($c['name'])?></option><?php endforeach;?></select></div><div class="table-wrap"><table class="modern-products-table"><thead><tr><th>Product</th><th>Category</th><th>Size</th><th>Price</th><th>Stock</th><th>Stock Adjust</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach($productGroups as $variants): $rowspan=count($variants); foreach($variants as $variantIndex=>$r):
  $stock=(float)$r['stock_qty'];$low=(float)$r['low_stock_level'];
  if(!$r['is_active']){$stockClass='inactive';$stockLabel='Inactive';}
  elseif($stock<=0){$stockClass='out';$stockLabel='Out of Stock';}
  elseif($stock<=$low){$stockClass='low';$stockLabel='Low Stock';}
  else{$stockClass='in';$stockLabel='In Stock';}
  $searchText=strtolower($r['name'].' '.$r['category'].' '.$r['size']);
?>
<tr id="product-<?=e($r['id'])?>" data-category="<?=e(strtolower($r['category']))?>" data-search="<?=e($searchText)?>" class="product-row stock-<?=$stockClass?> <?=$variantIndex===0?'group-start':'variant-divider'?>">
  <?php if($variantIndex===0):?>
  <td class="group-cell" rowspan="<?=$rowspan?>"><div class="product-cell"><?php if($r['image_path']):?><img class="product-thumb" src="<?=e(base_url($r['image_path']))?>" alt=""><?php endif;?><div><strong><?=e($r['name'])?></strong></div></div></td>
  <td class="group-cell category-group-cell" rowspan="<?=$rowspan?>"><span class="category-cell"><i class="category-dot" style="background:<?=e($r['category_color'])?>"></i><?=e($r['category'])?></span></td>
  <?php endif;?>
  <td class="size-cell"><?=e($r['size'])?></td>
  <td>LKR <?=number_format((float)$r['price'],2)?></td>
  <td><b class="stock-number stock-number-<?=$stockClass?>"><?=number_format($stock,0)?></b></td>
  <td><form class="stock-adjust-inline" method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="stock"><input type="hidden" name="id" value="<?=e($r['id'])?>"><input class="adjust-choice" type="number" name="qty" value="0" step="1" aria-label="Stock adjustment" required><button class="apply-stock" type="submit">Apply</button></form></td>
  <td><span class="stock-pill stock-pill-<?=$stockClass?>"><?=$stockLabel?></span></td>
  <td><div class="product-actions"><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=e($r['id'])?>"><button class="action-toggle-direct <?=$r['is_active']?'is-active':'is-inactive'?>" type="submit"><?=$r['is_active']?'Active':'Inactive'?></button></form><a class="edit-product-btn" href="product_edit.php?id=<?=e($r['id'])?>">✎ <span>Edit</span></a></div></td>
</tr>
<?php endforeach; endforeach;?>
</tbody></table></div></section>
<script>
document.querySelectorAll('.products-stock-panel form').forEach(function(form){
  form.addEventListener('submit',function(){sessionStorage.setItem('products-stock-scroll',String(window.scrollY));});
});
var savedScroll=sessionStorage.getItem('products-stock-scroll');
if(savedScroll!==null){
  sessionStorage.removeItem('products-stock-scroll');
  requestAnimationFrame(function(){window.scrollTo(0,Number(savedScroll)||0);});
}
const categoryBody=document.getElementById('categoryManagerBody');
const categoryToggle=document.getElementById('toggleCategories');
const categoryForm=document.getElementById('categoryAddForm');
const categoryAddTrigger=document.getElementById('categoryAddTrigger');
document.getElementById('showCategoryForm').addEventListener('click',function(){categoryForm.hidden=false;categoryAddTrigger.hidden=true;categoryForm.querySelector('input[name="name"]').focus();});
document.getElementById('cancelCategoryForm').addEventListener('click',function(){categoryForm.hidden=true;categoryAddTrigger.hidden=false;});
categoryToggle.addEventListener('click',function(){const willOpen=categoryBody.hidden;categoryBody.hidden=!willOpen;categoryToggle.textContent=willOpen?'▼':'▶';categoryToggle.setAttribute('aria-expanded',String(willOpen));categoryToggle.setAttribute('aria-label',willOpen?'Collapse categories':'Expand categories');if(!willOpen){categoryForm.hidden=true;categoryAddTrigger.hidden=false;}});
const productFormBody=document.getElementById('addProductBody');
const productFormToggle=document.getElementById('toggleProductForm');
productFormToggle.addEventListener('click',function(){const willOpen=productFormBody.hidden;productFormBody.hidden=!willOpen;this.textContent=willOpen?'▼':'▶';this.setAttribute('aria-expanded',String(willOpen));this.setAttribute('aria-label',willOpen?'Collapse add product form':'Expand add product form');if(willOpen){const first=productFormBody.querySelector('select,input');if(first)first.focus();}});
const search=document.getElementById('productSearch'),filter=document.getElementById('productCategoryFilter');
const newProductCategory=document.getElementById('newProductCategory'),newProductSize=document.getElementById('newProductSize');
function applyFixedNewProductSize(){const selected=newProductCategory.options[newProductCategory.selectedIndex],fixed=selected?.dataset.fixedSize||'';Array.from(newProductSize.options).forEach(function(option){option.hidden=!!fixed&&option.value!==fixed;option.disabled=!!fixed&&option.value!==fixed;});if(fixed)newProductSize.value=fixed;}
newProductCategory.addEventListener('change',applyFixedNewProductSize);applyFixedNewProductSize();
function filterProducts(){const term=search.value.trim().toLowerCase(),category=filter.value;document.querySelectorAll('.products-stock-panel tbody .product-row').forEach(function(row){const text=row.dataset.search||row.textContent.toLowerCase(),match=(!term||text.includes(term))&&(!category||row.dataset.category===category);row.hidden=!match;});}
search.addEventListener('input',filterProducts);filter.addEventListener('change',filterProducts);
</script>
<?php include __DIR__.'/../includes/footer.php'; ?>
