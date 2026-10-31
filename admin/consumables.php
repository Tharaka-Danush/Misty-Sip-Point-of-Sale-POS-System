<?php
require_once __DIR__.'/../includes/bootstrap.php';
require_admin();
$pdo=db();

function consumables_redirect(): never {
  header('Location: '.base_url('admin/consumables.php'));
  exit;
}

function apply_recommended_consumable_mappings(PDO $pdo): void {
  $categories=['bubble tea','boba','fresh juice','fruit salad','fruit tea','hot drinks','coffee & hot drinks','ice cream','milkshake','sundae'];
  $marks=implode(',',array_fill(0,count($categories),'?'));
  $delete=$pdo->prepare("DELETE pc FROM product_consumables pc JOIN products p ON p.id=pc.product_id JOIN categories cat ON cat.id=p.category_id WHERE LOWER(cat.name) IN ($marks)");
  $delete->execute($categories);
  $add=function(array $categoryNames,string $itemName,?string $size=null) use($pdo): void {
    $marks=implode(',',array_fill(0,count($categoryNames),'?'));
    $sql="INSERT IGNORE INTO product_consumables(product_id,consumable_id,qty_per_item) SELECT p.id,con.id,1 FROM products p JOIN categories cat ON cat.id=p.category_id JOIN consumables con ON con.name=? WHERE LOWER(cat.name) IN ($marks)";
    $params=array_merge([$itemName],$categoryNames);
    if($size!==null){$sql.=' AND p.size=?';$params[]=$size;}
    $s=$pdo->prepare($sql);$s->execute($params);
  };
  $addSizeCups=function(array $categoryNames) use($add): void {
    $add($categoryNames,'Small Cup','S'); $add($categoryNames,'Medium Cup','M');
    $add($categoryNames,'Medium Cup','U'); $add($categoryNames,'Large Cup','L');
  };
  $addSizeCups(['bubble tea','boba']); $add(['bubble tea','boba'],'Boba Straw');
  $addSizeCups(['fresh juice']); $add(['fresh juice'],'Regular Straw');
  $add(['fruit salad'],'Salad Cup'); $add(['fruit salad'],'Fork');
  $addSizeCups(['fruit tea']); $add(['fruit tea'],'Regular Straw');
  $addSizeCups(['hot drinks','coffee & hot drinks']); $add(['hot drinks','coffee & hot drinks'],'Cup Lid');
  $add(['ice cream'],'Sundae Cup'); $add(['ice cream'],'Spoon');
  $addSizeCups(['milkshake']); $add(['milkshake'],'Boba Straw');
  $add(['sundae'],'Sundae Cup'); $add(['sundae'],'Spoon');
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  verify_csrf();
  $action=(string)($_POST['action']??'');
  try {
    if ($action==='save_item') {
      $name=trim((string)($_POST['name']??''));
      $type=trim((string)($_POST['type']??''));
      $stock=max(0,(int)($_POST['opening_stock']??0));
      $low=max(0,(int)($_POST['low_stock_level']??0));
      if ($name===''||$type==='') throw new RuntimeException('Item name and type are required.');
      $s=$pdo->prepare('INSERT INTO consumables(name,type,stock_qty,low_stock_level) VALUES(?,?,?,?)');
      $s->execute([$name,$type,$stock,$low]);
      $id=(int)$pdo->lastInsertId();
      if($stock>0){$m=$pdo->prepare("INSERT INTO consumable_movements(consumable_id,user_id,movement_type,qty,note) VALUES(?,?,'opening',?,'Opening stock')");$m->execute([$id,auth_user()['id'],$stock]);}
      $_SESSION['flash']='Consumable added successfully.';
    } elseif ($action==='adjust_stock') {
      $id=(int)($_POST['id']??0); $qty=(int)($_POST['qty']??0); $cn=$pdo->prepare('SELECT name,stock_qty FROM consumables WHERE id=?');$cn->execute([$id]);$cr=$cn->fetch();
      if($id<1||$qty===0) throw new RuntimeException('Enter a stock adjustment other than zero.');
      $pdo->beginTransaction();
      $s=$pdo->prepare('UPDATE consumables SET stock_qty=stock_qty+? WHERE id=? AND stock_qty+?>=0');
      $s->execute([$qty,$id,$qty]);
      if(!$s->rowCount()) throw new RuntimeException('Stock cannot be reduced below zero.');
      $m=$pdo->prepare("INSERT INTO consumable_movements(consumable_id,user_id,movement_type,qty,note) VALUES(?,?,'adjustment',?,'Manual stock adjustment')");
      $m->execute([$id,auth_user()['id'],$qty]);
      $pdo->commit(); log_activity('Consumable Adjusted','stock','Consumable: '.($cr['name']??('#'.$id)).' • Change: '.($qty>0?'+':'').$qty.' • New Stock: '.((float)($cr['stock_qty']??0)+$qty),$id); $_SESSION['flash']='Consumable stock updated.';
    } elseif ($action==='toggle_item') {
      $id=(int)($_POST['id']??0);
      $s=$pdo->prepare('UPDATE consumables SET is_active=1-is_active WHERE id=?'); $s->execute([$id]);
      $_SESSION['flash']='Consumable status updated.';
    } elseif ($action==='update_item') {
      $id=(int)($_POST['id']??0); $name=trim((string)($_POST['name']??'')); $type=trim((string)($_POST['type']??'')); $low=max(0,(int)($_POST['low_stock_level']??0));
      if($id<1||$name===''||$type==='') throw new RuntimeException('Complete all item fields.');
      $s=$pdo->prepare('UPDATE consumables SET name=?,type=?,low_stock_level=? WHERE id=?');$s->execute([$name,$type,$low,$id]);
      $_SESSION['flash']='Consumable details updated.';
    } elseif ($action==='save_mapping') {
      $productId=(int)($_POST['product_id']??0); $consumableId=(int)($_POST['consumable_id']??0); $qty=(float)($_POST['qty_per_item']??0);
      if($productId<1||$consumableId<1||$qty<=0) throw new RuntimeException('Select a product, consumable and valid quantity.');
      $s=$pdo->prepare('INSERT INTO product_consumables(product_id,consumable_id,qty_per_item) VALUES(?,?,?) ON DUPLICATE KEY UPDATE qty_per_item=VALUES(qty_per_item)');
      $s->execute([$productId,$consumableId,$qty]); $_SESSION['flash']='Product usage mapping saved.';
    } elseif ($action==='delete_mapping') {
      $s=$pdo->prepare('DELETE FROM product_consumables WHERE id=?');$s->execute([(int)($_POST['id']??0)]);
      $_SESSION['flash']='Usage mapping removed.';
    } elseif ($action==='apply_recommended_mappings') {
      $pdo->beginTransaction(); apply_recommended_consumable_mappings($pdo); $pdo->commit();
      $_SESSION['flash']='Recommended mappings applied to all products and sizes.';
    }
  } catch(Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack(); $_SESSION['flash']=$e->getMessage();
  }
  consumables_redirect();
}

$items=$pdo->query('SELECT * FROM consumables ORDER BY is_active DESC,name')->fetchAll();
$products=$pdo->query("SELECT p.id,p.name,p.size,c.name category_name FROM products p JOIN categories c ON c.id=p.category_id WHERE p.is_active=1 ORDER BY c.name,p.name,FIELD(p.size,'S','M','L')")->fetchAll();
$mappings=$pdo->query('SELECT pc.id,pc.product_id,pc.qty_per_item,p.name product_name,p.size,c.name consumable_name FROM product_consumables pc JOIN products p ON p.id=pc.product_id JOIN consumables c ON c.id=pc.consumable_id ORDER BY p.name,FIELD(p.size,\'S\',\'M\',\'L\'),c.name')->fetchAll();
$mappingGroups=[];
foreach($mappings as $map){
  $key=(string)$map['product_id'];
  if(!isset($mappingGroups[$key]))$mappingGroups[$key]=['label'=>$map['product_name'].' ('.$map['size'].')','items'=>[]];
  $mappingGroups[$key]['items'][]=$map;
}
$pageTitle='Packaging & Consumables'; $pageSubtitle='Manage cups, lids, straws and serving items';
require __DIR__.'/../includes/header.php';
?>
<style>
/* Keep the inventory headings, values and controls aligned centrally. */
.inventory-panel .consumable-table th:nth-child(n+3),
.inventory-panel .consumable-table td:nth-child(n+3){text-align:center}
.inventory-panel .stock-form,
.inventory-panel .action-row{justify-content:center}
</style>
<section class="consumables-page">
  <details class="consumable-panel add-panel">
    <summary><button type="button" class="panel-toggle" aria-label="Open Add New Consumable" aria-expanded="false">▶</button><span>Add New Consumable</span></summary>
    <form method="post" class="compact-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_item">
      <label>Item Name<input name="name" required placeholder="e.g. Medium Cup"></label>
      <label>Type<select name="type" required><option>Cup</option><option>Lid</option><option>Straw</option><option>Cutlery</option><option>Container</option><option>Other</option></select></label>
      <label>Opening Stock<input type="number" name="opening_stock" min="0" step="1" value="0" required></label>
      <label>Low Stock Level<input type="number" name="low_stock_level" min="0" step="1" value="20" required></label>
      <button class="btn primary" type="submit">Save Item</button>
    </form>
  </details>

  <section class="consumable-panel inventory-panel"><div class="panel-heading"><div><h2>Consumable Inventory</h2><p>Cups, lids, straws and serving items</p></div><input id="consumableSearch" placeholder="Search consumables..." aria-label="Search consumables"></div>
    <div class="table-wrap"><table class="consumable-table"><thead><tr><th>Item</th><th>Type</th><th>Current Stock</th><th>Stock Adjust</th><th>Low Stock Level</th><th>Status</th><th>Action</th></tr></thead><tbody>
    <?php foreach($items as $item): $stock=(float)$item['stock_qty'];$low=(float)$item['low_stock_level'];$critical=$stock<=max(1,$low/2);$lowFlag=$stock<=$low; ?>
      <tr data-search="<?=e(strtolower($item['name'].' '.$item['type']))?>"><td><strong><?=e($item['name'])?></strong></td><td><?=e($item['type'])?></td><td class="stock-number<?= $critical?' critical':($lowFlag?' low':'') ?>"><?=e(number_format($stock,0))?></td>
        <td><form method="post" class="stock-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="adjust_stock"><input type="hidden" name="id" value="<?=e($item['id'])?>"><input type="number" name="qty" step="1" value="0" required><button class="btn primary" type="submit">Apply</button></form></td>
        <td><?=e(number_format($low,0))?></td><td><span class="status-pill <?= !$item['is_active']?'inactive':($critical?'critical':($lowFlag?'low':'ok')) ?>"><?= !$item['is_active']?'Inactive':($critical?'Critical':($lowFlag?'Low Stock':'In Stock')) ?></span></td>
        <td><div class="action-row"><button type="button" class="btn edit-consumable" data-id="<?=e($item['id'])?>" data-name="<?=e($item['name'])?>" data-type="<?=e($item['type'])?>" data-low="<?=e($item['low_stock_level'])?>">Edit</button><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle_item"><input type="hidden" name="id" value="<?=e($item['id'])?>"><button class="btn <?= $item['is_active']?'active-btn':'inactive-btn' ?>" type="submit"><?= $item['is_active']?'Active':'Inactive' ?></button></form></div></td></tr>
    <?php endforeach; ?></tbody></table></div>
  </section>

  <details class="consumable-panel mapping-panel"><summary><button type="button" class="panel-toggle" aria-label="Open Product Usage Mapping" aria-expanded="false">▶</button><span>Product Usage Mapping <small>Automatically deducted when a sale is completed</small></span></summary>
    <form method="post" class="recommended-map-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="apply_recommended_mappings"><p><strong>Recommended mapping:</strong> size-based cups with the correct straw or cutlery.</p><button class="btn primary" type="submit">Apply Recommended Mapping</button></form>
    <details class="mapping-assignment"><summary>Manual Assignment</summary>
      <form method="post" class="mapping-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_mapping">
        <label>Product & Size<select name="product_id" required><option value="">Select product</option><?php foreach($products as $p):?><option value="<?=e($p['id'])?>"><?=e($p['name'].' ('.$p['size'].')')?></option><?php endforeach;?></select></label>
        <label>Consumable<select name="consumable_id" required><option value="">Select item</option><?php foreach($items as $item)if($item['is_active']):?><option value="<?=e($item['id'])?>"><?=e($item['name'])?></option><?php endif;?></select></label>
        <label>Qty per Sale<input type="number" name="qty_per_item" min="0.01" step="0.01" value="1" required></label><button class="btn primary" type="submit">Assign Consumable</button>
      </form>
    </details>
    <div class="mapping-list grouped-mapping-list"><?php if(!$mappingGroups):?><p class="empty-state">No product mappings yet.</p><?php else: foreach($mappingGroups as $group):?><div class="mapping-row grouped-mapping-row"><strong><?=e($group['label'])?></strong><div class="mapping-badges"><?php foreach($group['items'] as $map):?><form method="post" class="mapping-badge"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_mapping"><input type="hidden" name="id" value="<?=e($map['id'])?>"><span><?=e($map['consumable_name'])?> × <?=e(rtrim(rtrim(number_format((float)$map['qty_per_item'],2),'0'),'.'))?></span><button type="submit" aria-label="Remove <?=e($map['consumable_name'])?> mapping">×</button></form><?php endforeach;?></div></div><?php endforeach;endif;?></div>
  </details>
</section>

<dialog id="editConsumableDialog" class="consumable-dialog"><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="update_item"><input type="hidden" name="id" id="editConsumableId"><h2>Edit Consumable</h2><label>Item Name<input name="name" id="editConsumableName" required></label><label>Type<input name="type" id="editConsumableType" required></label><label>Low Stock Level<input type="number" min="0" step="1" name="low_stock_level" id="editConsumableLow" required></label><div><button type="button" class="btn" id="cancelConsumableEdit">Cancel</button><button class="btn primary" type="submit">Save Changes</button></div></form></dialog>
<script>
const search=document.getElementById('consumableSearch');search?.addEventListener('input',()=>{const q=search.value.trim().toLowerCase();document.querySelectorAll('.consumable-table tbody tr').forEach(row=>row.hidden=!row.dataset.search.includes(q));});
document.querySelectorAll('.consumable-panel>summary').forEach(summary=>{
  const details=summary.parentElement,button=summary.querySelector('.panel-toggle');
  if(!button)return;
  summary.addEventListener('click',event=>event.preventDefault());
  button.addEventListener('click',event=>{
    event.preventDefault();event.stopPropagation();details.open=!details.open;
    button.textContent=details.open?'▼':'▶';
    button.setAttribute('aria-expanded',details.open?'true':'false');
    button.setAttribute('aria-label',(details.open?'Close ':'Open ')+summary.querySelector('span').childNodes[0].textContent.trim());
  });
});
const dialog=document.getElementById('editConsumableDialog');document.querySelectorAll('.edit-consumable').forEach(btn=>btn.addEventListener('click',()=>{document.getElementById('editConsumableId').value=btn.dataset.id;document.getElementById('editConsumableName').value=btn.dataset.name;document.getElementById('editConsumableType').value=btn.dataset.type;document.getElementById('editConsumableLow').value=btn.dataset.low;dialog.showModal();}));document.getElementById('cancelConsumableEdit')?.addEventListener('click',()=>dialog.close());
</script>
<?php require __DIR__.'/../includes/footer.php'; ?>
