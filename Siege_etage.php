<?php
require_once 'config.php';
if(!isLoggedIn()){ redirect('login.php'); }
requireModuleAccess($pdo, 'siege');
$username = $_SESSION['username'] ?? 'Utilisateur';
$canCreate = hasModulePermission($pdo, 'siege', 'create');
$canUpdate = hasModulePermission($pdo, 'siege', 'update');
$canDelete = hasModulePermission($pdo, 'siege', 'delete');
/* Désactiver le cache navigateur pour éviter la restauration d'un ancien état */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ── Ajouter les colonnes manquantes si elles n'existent pas encore ──
$_alter = [
  "ALTER TABLE bureaux ADD COLUMN id          INT AUTO_INCREMENT PRIMARY KEY FIRST",
  "ALTER TABLE bureaux ADD COLUMN nom         VARCHAR(60)  NULL",
  "ALTER TABLE bureaux ADD COLUMN prenom      VARCHAR(60)  NULL",
  "ALTER TABLE bureaux ADD COLUMN emploi      VARCHAR(150) NULL",
  "ALTER TABLE bureaux ADD COLUMN statut      VARCHAR(50)  NULL DEFAULT 'Libre'",
  "ALTER TABLE bureaux ADD COLUMN superficie  DECIMAL(8,2) NULL",
  "ALTER TABLE bureaux ADD COLUMN superficie_droit DECIMAL(8,2) NULL",
  "ALTER TABLE bureaux ADD COLUMN notes       VARCHAR(200) NULL",
  "ALTER TABLE bureaux ADD COLUMN decision_pdf VARCHAR(600) NULL",
  "ALTER TABLE bureaux ADD COLUMN ancien_bureau VARCHAR(100) NULL",
];
foreach($_alter as $_sql){ try{ $pdo->exec($_sql); }catch(Exception $e){} }

// ── Corriger les id NULL : forcer un id auto pour les lignes sans id ──
try {
  // Récupérer toutes les lignes sans id (ou id=0)
  $rows_no_id = $pdo->query("SELECT ref_bureau, etage FROM bureaux WHERE id IS NULL OR id = 0 LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows_no_id as $row){
    // On ne peut pas UPDATE WHERE id IS NULL proprement, donc on utilise ref_bureau+etage
    $pdo->prepare("UPDATE bureaux SET id = NULL WHERE ref_bureau = ? AND etage = ? AND (id IS NULL OR id = 0) LIMIT 1")->execute([$row['ref_bureau'], $row['etage']]);
  }
  // Forcer MySQL à regénérer les AUTO_INCREMENT manquants
  $pdo->exec("SET @cnt = 0; UPDATE bureaux SET id = (@cnt := @cnt + 1) WHERE id IS NULL OR id = 0 ORDER BY etage, ref_bureau");
} catch(Exception $e){}

// ── Synchroniser superficie ← surf_m2 et superficie_droit ← surf_droit si vides ──
try {
  $pdo->exec("UPDATE bureaux SET superficie = surf_m2 WHERE superficie IS NULL AND surf_m2 IS NOT NULL");
  $pdo->exec("UPDATE bureaux SET superficie_droit = surf_droit WHERE superficie_droit IS NULL AND surf_droit IS NOT NULL");
} catch(Exception $e){}

// ── Corriger la vue siege_bureaux : jointure double (bureau_id ET mle en fallback) ──
// Ceci corrige les bureaux qui ont un mle dans bureaux mais sans bureau_id dans employes.
try {
  $pdo->exec("CREATE OR REPLACE VIEW siege_bureaux AS
    SELECT
      b.id, b.etage, b.ref_bureau,
      COALESCE(b.superficie, b.surf_m2)                                                 AS superficie,
      COALESCE(b.superficie_droit, b.surf_droit)                                        AS superficie_droit,
      b.surf_m2, b.surf_droit, b.statut, b.ancien_bureau,
      COALESCE(b.notes, b.remarque)                                                     AS notes,
      b.remarque, b.decision_pdf,
      COALESCE(b.mle,              e_id.mle,              e_mle.mle)                    AS mle,
      COALESCE(b.nom,              e_id.nom,              e_mle.nom)                    AS nom,
      COALESCE(b.prenom,           e_id.prenom,           e_mle.prenom)                 AS prenom,
      COALESCE(b.emploi,           e_id.emploi,           e_mle.emploi)                 AS emploi,
      COALESCE(b.l_fonct,          e_id.l_fonct,          e_mle.l_fonct)                AS l_fonct,
      COALESCE(b.l_entite,         e_id.l_entite,         e_mle.l_entite)               AS l_entite,
      COALESCE(b.l_classf,         e_id.l_classf,         e_mle.l_classf)               AS l_classf,
      COALESCE(b.direction,        e_id.direction,        e_mle.direction)              AS direction,
      COALESCE(b.direction_centrale,e_id.direction_centrale,e_mle.direction_centrale)   AS direction_centrale
    FROM bureaux b
    LEFT JOIN employes e_id  ON e_id.bureau_id = b.id
    LEFT JOIN employes e_mle ON LPAD(TRIM(e_mle.mle),5,'0') = LPAD(TRIM(b.mle),5,'0') AND e_id.id IS NULL
  ");
} catch(Exception $e){}

// ── Charger les normes depuis la base de données (table normes_surfaces) ──
$NORMES = [];
try {
  $normes_rows = $pdo->query("SELECT poste, surf_m2 FROM normes_surfaces WHERE surf_m2 IS NOT NULL ORDER BY surf_m2 DESC")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($normes_rows as $nr) {
    $NORMES[trim($nr['poste'])] = floatval($nr['surf_m2']);
  }
} catch (Exception $e) { $NORMES = []; }
// ── Fallback si la table est vide ──
if (empty($NORMES)) {
  $NORMES = [
    'PDG'=>42,'Président Directeur Général'=>42,
    'Directeur Général Adjoint'=>42,'DGA'=>42,
    'Secrétaire Général'=>38,'Directeur Central'=>38,
    'Directeur'=>24,'Chef de Département'=>16,
    'Chef de Service'=>12,'Cadre'=>12,
    'Haute Maîtrise (seul)'=>10,'Haute Maîtrise (2)'=>15,
    'Secrétaire'=>9,'Maîtrise (seul)'=>9,
    'Maîtrise (2)'=>12,'Maîtrise (3)'=>18,
  ];
}

$ETAGES=[
  0=>['label'=>'Rez-de-Chaussée','short'=>'RDC','sub'=>'BOC · DCSI · DCOA · DCP · DCF','color'=>'#6D28D9,#7C3AED'],
  1=>['label'=>'1er Étage',      'short'=>'1',  'sub'=>'DCRH · DCA · DSVP · Call Center','color'=>'#0F2563,#1D4ED8'],
  2=>['label'=>'2ème Étage',     'short'=>'2',  'sub'=>'DCF · DCP · Catering · DRC',    'color'=>'#701A75,#A21CAF'],
  3=>['label'=>'3ème Étage',     'short'=>'3',  'sub'=>'DCOA · DCP · DRM',              'color'=>'#C8102E,#EF4444'],
  4=>['label'=>'4ème Étage',     'short'=>'4',  'sub'=>'DCC · SPOD · DCRH · DAJ',       'color'=>'#0F2563,#1D4ED8'],
  5=>['label'=>'5ème Étage',     'short'=>'5',  'sub'=>'Direction Générale · SG',       'color'=>'#9B0E23,#C8102E'],
];

if($_SERVER['REQUEST_METHOD']==='POST'){
  $action=$_POST['action']??''; $ep=intval($_POST['etage']??0);

  if($action==='add'){requireModulePermission($pdo, 'siege', 'create');$f=['etage','ref_bureau','superficie','superficie_droit','mle','nom','prenom','emploi','l_fonct','l_entite','direction','direction_centrale','l_classf','statut','notes'];$pdo->prepare("INSERT INTO bureaux (".implode(',',$f).") VALUES (".implode(',',array_fill(0,count($f),'?')).")")->execute(array_map(fn($k)=>($_POST[$k]??null)?:null,$f));}
  if($action==='edit'){
    requireModulePermission($pdo, 'siege', 'update');
    $f=['ref_bureau','superficie','superficie_droit','mle','nom','prenom','emploi','l_fonct','l_entite','direction','direction_centrale','l_classf','statut','notes'];
    $v=array_map(fn($k)=>($_POST[$k]??null)?:null,$f);
    $v[]=$_POST['id'];
    $pdo->prepare("UPDATE bureaux SET ".implode(', ',array_map(fn($k)=>"$k=?",$f))." WHERE id=?")->execute($v);
    // ── Correction bug carte : si un MLE est assigné à ce bureau, le retirer de tout autre bureau ──
    $new_mle = trim($_POST['mle']??'');
    if($new_mle){
      $pdo->prepare(
        "UPDATE bureaux SET mle=NULL, nom=NULL, prenom=NULL, emploi=NULL,
         l_fonct=NULL, l_entite=NULL, direction=NULL, direction_centrale=NULL,
         l_classf=NULL, statut='Libre'
         WHERE TRIM(mle)=? AND id!=?"
      )->execute([$new_mle, intval($_POST['id'])]);
    }
  }
  if($action==='delete'){requireModulePermission($pdo, 'siege', 'delete');$pdo->prepare("DELETE FROM bureaux WHERE id=?")->execute([$_POST['id']]);}

  if($action==='upload_etage'&&!empty($_FILES['plan_etage']['tmp_name'])){
    requireModulePermission($pdo, 'siege', 'update');
    $dir='documents/siege/etages/'; if(!is_dir($dir)) mkdir($dir,0755,true);
    $ext=strtolower(pathinfo($_FILES['plan_etage']['name'],PATHINFO_EXTENSION));
    $fn='etage_'.$ep.'.'.$ext;
    move_uploaded_file($_FILES['plan_etage']['tmp_name'],$dir.$fn);
  }

  if($action==='upload_decision'){
    requireModulePermission($pdo, 'siege', 'update');
    $id=intval($_POST['id']);
    if(!empty($_FILES['decision_file']['tmp_name'])){
      $dir='documents/siege/decisions/'; if(!is_dir($dir)) mkdir($dir,0755,true);
      $ext=strtolower(pathinfo($_FILES['decision_file']['name'],PATHINFO_EXTENSION));
      $fn='decision_'.$id.'_'.time().'.'.$ext;
      move_uploaded_file($_FILES['decision_file']['tmp_name'],$dir.$fn);
      $pdo->prepare("UPDATE bureaux SET decision_pdf=? WHERE id=?")->execute([$dir.$fn,$id]);
    }
    header("Location: ?etage=$ep"); exit;
  }

  if($action==='transfer'){
    requireModulePermission($pdo, 'siege', 'update');
    $id      = intval($_POST['id']??0);
    $new_ref = trim($_POST['new_ref_bureau']??'');
    $old_ref = trim($_POST['old_ref_bureau']??'');
    // ── Chercher par ref_bureau (fiable même si id=NULL) + fallback par id ──
    $ancienStmt = $pdo->prepare("SELECT * FROM siege_bureaux WHERE LOWER(TRIM(ref_bureau)) = LOWER(TRIM(?))");
    $ancienStmt->execute([$old_ref]);
    $ancienBureau = $ancienStmt->fetch(PDO::FETCH_ASSOC);
    if(!$ancienBureau && $id > 0){
      $tmp2 = $pdo->prepare("SELECT * FROM siege_bureaux WHERE id=?");
      $tmp2->execute([$id]);
      $ancienBureau = $tmp2->fetch(PDO::FETCH_ASSOC);
    }
    $dir='documents/siege/decisions/'; if(!is_dir($dir)) mkdir($dir,0755,true);
    $decision_path = null;
    if(!empty($_FILES['decision_file']['tmp_name'])){
      $ext=strtolower(pathinfo($_FILES['decision_file']['name'],PATHINFO_EXTENSION));
      $fn='decision_'.$id.'_'.time().'.'.$ext;
      move_uploaded_file($_FILES['decision_file']['tmp_name'],$dir.$fn);
      $decision_path = $dir.$fn;
    }
    elseif(!empty($_POST['pdf_base64'])){
      $raw = $_POST['pdf_base64'];
      if(strpos($raw,'base64,')!==false){ $raw = substr($raw, strpos($raw,'base64,')+7); }
      $raw = preg_replace('/\s+/','',$raw);
      $decoded = base64_decode($raw, true);
      if($decoded !== false && strlen($decoded) > 100){
        $fn='decision_'.$id.'_'.time().'.pdf';
        file_put_contents($dir.$fn, $decoded);
        $decision_path = $dir.$fn;
      }
    }
    if($new_ref && $ancienBureau){
      $champs_occupant = ['mle','nom','prenom','emploi','l_fonct','l_entite','direction','direction_centrale','l_classf'];
      $setNew = implode(',', array_map(fn($c)=>"$c=?", $champs_occupant));
      $valsNew = array_map(fn($c)=>$ancienBureau[$c]??null, $champs_occupant);
      $setNew .= ',statut=?,ancien_bureau=?';
      $valsNew[] = !empty($_POST['statut_nouveau']) ? $_POST['statut_nouveau'] : 'Occupé';
      $valsNew[] = $old_ref;
      if($decision_path){ $setNew .= ',decision_pdf=?'; $valsNew[] = $decision_path; }
      /* ── Chercher le bureau cible par ID (lookup insensible à la casse) ── */
      $tgtStmt = $pdo->prepare("SELECT id FROM bureaux WHERE LOWER(TRIM(ref_bureau)) = LOWER(TRIM(?))");
      $tgtStmt->execute([$new_ref]);
      $tgtRow = $tgtStmt->fetch(PDO::FETCH_ASSOC);
      // Mettre à jour le nouveau bureau par id si disponible, sinon par ref_bureau
      if($tgtRow && !empty($tgtRow['id'])){
        $valsNew[] = $tgtRow['id'];
        $pdo->prepare("UPDATE bureaux SET $setNew WHERE id=?")->execute($valsNew);
      } else {
        $valsNew[] = $new_ref;
        $pdo->prepare("UPDATE bureaux SET $setNew WHERE LOWER(TRIM(ref_bureau)) = LOWER(TRIM(?))")->execute($valsNew);
      }
      $statut_ancien = !empty($_POST['statut_ancien']) ? $_POST['statut_ancien'] : 'Libre';
      // Vider l'ancien bureau par ref_bureau (fiable même si id=NULL)
      $pdo->prepare("UPDATE bureaux SET mle=NULL, nom=NULL, prenom=NULL, emploi=NULL, l_fonct=NULL, l_entite=NULL, direction=NULL, direction_centrale=NULL, l_classf=NULL, statut=?, ancien_bureau=?, decision_pdf=NULL WHERE LOWER(TRIM(ref_bureau)) = LOWER(TRIM(?))")->execute([$statut_ancien, $new_ref, $old_ref]);
    } else {
      $sets=[]; $vals=[];
      $sets[]='ancien_bureau=?'; $vals[]=$old_ref;
      if($decision_path){ $sets[]='decision_pdf=?'; $vals[]=$decision_path; }
      if(!empty($_POST['statut_ancien'])){ $sets[]='statut=?'; $vals[]=$_POST['statut_ancien']; }
      if($sets){ $vals[]=$old_ref; $pdo->prepare("UPDATE bureaux SET ".implode(',',$sets)." WHERE LOWER(TRIM(ref_bureau)) = LOWER(TRIM(?))")->execute($vals); }
    }
    // ── Rediriger vers l'étage et le bureau du nouveau bureau après transfert ──
    $redirect_etage = $ep;
    $redirect_sel   = '';
    if($new_ref){
      $stmt = $pdo->prepare("SELECT id, etage FROM bureaux WHERE LOWER(TRIM(ref_bureau)) = LOWER(TRIM(?))");
      $stmt->execute([$new_ref]);
      $nbr = $stmt->fetch(PDO::FETCH_ASSOC);
      if($nbr){ $redirect_etage = intval($nbr['etage']); $redirect_sel = intval($nbr['id']); }
    }
    header("Location: ?etage=$redirect_etage".($redirect_sel ? "&sel=$redirect_sel" : "")); exit;
  }

  if($action==='cle_action'){
    requireModulePermission($pdo, 'siege', 'update');
    $id   = intval($_POST['id']??0);
    $type = $_POST['cle_type']??'';
    $now  = date('Y-m-d H:i:s');

    if($type==='remise'){
      // Bureau libre : Bahri a pris la clé → cle_detachee=1, cle_detachee_par='Mme. Bahri', cle_prise_date=now
      $pdo->prepare("UPDATE bureaux SET cle_detachee=1, cle_detachee_par='M. Bahri', cle_prise_date=?, cle_prise_par=NULL WHERE id=?")
          ->execute([$now,$id]);
    }elseif($type==='non_remise'){
      // Bureau libre : Bahri n'a PAS encore pris la clé
      $pdo->prepare("UPDATE bureaux SET cle_detachee=0, cle_detachee_par='non_remise', cle_prise_date=?, cle_prise_par=NULL WHERE id=?")
          ->execute([$now,$id]);
    }elseif($type==='prise'){
      // Bureau occupé : l'employé a pris la clé
      $preneur = trim($_POST['cle_preneur']??'');
      $pdo->prepare("UPDATE bureaux SET cle_detachee=1, cle_prise_par=?, cle_prise_date=?, cle_detachee_par=NULL WHERE id=?")
          ->execute([$preneur,$now,$id]);
    }elseif($type==='non_prise'){
      // Bureau occupé : l'employé n'a pas encore pris la clé
      $pdo->prepare("UPDATE bureaux SET cle_detachee=0, cle_detachee_par='non_prise', cle_prise_date=?, cle_prise_par=NULL WHERE id=?")
          ->execute([$now,$id]);
    }elseif($type==='reset'){
      $pdo->prepare("UPDATE bureaux SET cle_detachee=0, cle_detachee_par=NULL, cle_prise_date=NULL, cle_prise_par=NULL WHERE id=?")
          ->execute([$id]);
    }
    // Return JSON for AJAX
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true,'now'=>$now,'type'=>$type]);
    exit;
  }

  header("Location: ?etage=$ep"); exit;
}

$tous=[];
try{
  // ── Jointure double : d'abord via employes.bureau_id = bureaux.id (vue siege_bureaux),
  //    puis fallback via employes.mle = bureaux.mle pour les bureaux sans bureau_id dans employes.
  $tous=$pdo->query("
    SELECT
      b.id, b.etage, b.ref_bureau,
      COALESCE(b.mle, e_id.mle, e_mle.mle)                       AS mle,
      COALESCE(b.nom, e_id.nom, e_mle.nom)                       AS nom,
      COALESCE(b.prenom, e_id.prenom, e_mle.prenom)              AS prenom,
      COALESCE(b.emploi, e_id.emploi, e_mle.emploi)              AS emploi,
      COALESCE(b.l_fonct, e_id.l_fonct, e_mle.l_fonct)          AS l_fonct,
      COALESCE(b.l_entite, e_id.l_entite, e_mle.l_entite)       AS l_entite,
      COALESCE(b.direction, e_id.direction, e_mle.direction)     AS direction,
      COALESCE(b.direction_centrale, e_id.direction_centrale, e_mle.direction_centrale) AS direction_centrale,
      COALESCE(b.l_classf, e_id.l_classf, e_mle.l_classf)       AS l_classf,
      b.statut, b.ancien_bureau, b.decision_pdf,
      COALESCE(b.superficie, b.surf_m2)                          AS superficie,
      COALESCE(b.superficie_droit, b.surf_droit)                 AS superficie_droit,
      COALESCE(b.notes, b.remarque)                              AS notes
    FROM bureaux b
    LEFT JOIN employes e_id  ON e_id.bureau_id = b.id
    LEFT JOIN employes e_mle ON LPAD(TRIM(e_mle.mle),5,'0') = LPAD(TRIM(b.mle),5,'0') AND e_id.id IS NULL
    ORDER BY b.etage ASC, b.ref_bureau ASC
  ")->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $e){ $tous=[]; }

// Ajouter les colonnes clé depuis la table bureaux (pas la vue)
foreach($tous as &$b){
  $b['cle_detachee']=0; $b['cle_detachee_par']=null;
  $b['cle_prise_date']=null; $b['cle_prise_par']=null;
}
unset($b);
try{
  $cleRows=$pdo->query("SELECT id, cle_detachee, cle_detachee_par, cle_prise_date, cle_prise_par FROM bureaux")->fetchAll(PDO::FETCH_ASSOC);
  $cleMap=[];
  foreach($cleRows as $r) $cleMap[(string)$r['id']]=$r;
  foreach($tous as &$b){
    $k=(string)($b['id']??'');
    if(isset($cleMap[$k])){
      $b['cle_detachee']    =$cleMap[$k]['cle_detachee'];
      $b['cle_detachee_par']=$cleMap[$k]['cle_detachee_par'];
      $b['cle_prise_date']  =$cleMap[$k]['cle_prise_date'];
      $b['cle_prise_par']   =$cleMap[$k]['cle_prise_par'];
    }
  }
  unset($b);
}catch(Exception $e){}

// Si id est NULL, générer un identifiant temporaire basé sur etage+ref_bureau
foreach($tous as &$b){
  if(empty($b['id'])){
    $b['id'] = 'tmp_'.intval($b['etage']).'_'.preg_replace('/[^a-zA-Z0-9]/','',$b['ref_bureau']??'x');
  }
}
unset($b);
// Normaliser statut basé sur mle si absent
foreach($tous as &$b){
  if(empty($b['statut'])) $b['statut'] = (!empty($b['mle']) ? 'Occupé' : 'Libre');
}
unset($b);
$by_etage=[];foreach($tous as $b)$by_etage[intval($b['etage'])][]=$b;
$stats=[];foreach($ETAGES as $n=>$_){
  $l=$by_etage[$n]??[];
  $occ=count(array_filter($l,fn($b)=>!empty($b['mle'])||(($b['statut']??'')==='Occupé')));
  $hn=count(array_filter($l,fn($b)=>!empty($b['l_fonct'])&&isset($NORMES[$b['l_fonct']])&&!empty($b['superficie'])&&(floatval($b['superficie'])-$NORMES[$b['l_fonct']])>2));
  $stats[$n]=['total'=>count($l),'occupes'=>$occ,'libres'=>count($l)-$occ,'hn'=>$hn,'m2'=>array_sum(array_column($l,'superficie'))];
}
$ea=intval($_GET['etage']??0); if(!isset($ETAGES[$ea]))$ea=0;
$econf=$ETAGES[$ea];
$c1ea=explode(',',$econf['color'])[0]; $c2ea=explode(',',$econf['color'])[1];

// ── Notifications : incohérences de données + transferts récents ──
$notifs = [];
try {
  $rows = $pdo->query("SELECT ref_bureau, etage FROM bureaux WHERE statut='Occupé' AND (mle IS NULL OR TRIM(mle)='') LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as $r) $notifs[]=['type'=>'danger','icon'=>'alert-triangle','titre'=>'Bureau occupé sans employé','msg'=>'Bureau '.$r['ref_bureau'].' (étage '.$r['etage'].') est marqué Occupé mais n\'a aucun MLe assigné.','cat'=>'incoherence'];
} catch(Exception $e){}
try {
  // Normalisation LPAD : compare les deux MLEs sur 5 chiffres pour éviter '9913' vs '09913'
  $rows = $pdo->query("
    SELECT b.ref_bureau, b.etage, b.mle
    FROM bureaux b
    LEFT JOIN employes e
      ON LPAD(TRIM(e.mle),5,'0') = LPAD(TRIM(b.mle),5,'0')
    WHERE b.statut='Occupé'
      AND b.mle IS NOT NULL
      AND TRIM(b.mle) != ''
      AND b.mle REGEXP '^[0-9]+$'
      AND e.id IS NULL
    LIMIT 20
  ")->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as $r) $notifs[]=['type'=>'danger','icon'=>'user-x','titre'=>'Employé introuvable','msg'=>'Bureau '.$r['ref_bureau'].' — MLe '.$r['mle'].' absent de la table employés.','cat'=>'incoherence'];
} catch(Exception $e){}
try {
  $rows = $pdo->query("SELECT mle, COUNT(*) AS nb, GROUP_CONCAT(ref_bureau ORDER BY ref_bureau SEPARATOR ', ') AS refs FROM bureaux WHERE statut='Occupé' AND mle IS NOT NULL AND TRIM(mle)!='' GROUP BY mle HAVING nb>1 LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as $r) $notifs[]=['type'=>'warning','icon'=>'copy','titre'=>'MLe en double','msg'=>'MLe '.$r['mle'].' apparaît dans '.$r['nb'].' bureaux : '.$r['refs'].'.','cat'=>'incoherence'];
} catch(Exception $e){}
try {
  $rows = $pdo->query("SELECT b.ref_bureau, b.etage, e.mle, e.nom, b2.ref_bureau AS ancien_bureau FROM bureaux b JOIN employes e ON LPAD(TRIM(e.mle),5,'0')=LPAD(TRIM(b.mle),5,'0') LEFT JOIN bureaux b2 ON b2.id=e.bureau_id WHERE b.statut='Occupé' AND e.bureau_id IS NOT NULL AND e.bureau_id!=b.id LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as $r) $notifs[]=['type'=>'warning','icon'=>'arrows-left-right','titre'=>'Bureau non synchronisé','msg'=>($r['nom']??'MLe '.$r['mle']).' est dans '.$r['ref_bureau'].' mais employes.bureau_id pointe vers '.($r['ancien_bureau']??'bureau inconnu').'.','cat'=>'incoherence'];
} catch(Exception $e){}
try {
  $rows = $pdo->query("SELECT ref_bureau, etage, ancien_bureau, mle, nom, prenom FROM bureaux WHERE ancien_bureau IS NOT NULL AND ancien_bureau!='' AND statut='Occupé' ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as $r){
    $qui=trim(($r['prenom']??'').' '.($r['nom']??'')); if(!$qui) $qui='MLe '.$r['mle'];
    $notifs[]=['type'=>'info','icon'=>'transfer','titre'=>'Transfert enregistré','msg'=>$qui.' a été transféré de '.$r['ancien_bureau'].' vers '.$r['ref_bureau'].' (étage '.$r['etage'].').','cat'=>'transfert'];
  }
} catch(Exception $e){}
$notifs_json = json_encode($notifs, JSON_UNESCAPED_UNICODE);

$plans_etage=[];
foreach($ETAGES as $n=>$_){
  $dir='documents/siege/etages/';
  foreach(['jpg','jpeg','png','webp','pdf'] as $ext){
    $path=$dir.'etage_'.$n.'.'.$ext;
    if(file_exists($path)){$plans_etage[$n]=$path; break;}
  }
}

// Embedded plan images (base64) — indexed by floor number
$embedded_plans = [
    "plan de siege/bureaux_siege0R_page-0009.jpg",
    "plan de siege/bureaux_siege1er_page-0004.jpg",
    "plan de siege/bureaux_siege2_page-0005.jpg",
    "plan de siege/bureaux_siege3_page-0006.jpg",
    "plan de siege/bureaux_siege4_page-0007.jpg",
    "plan de siege/bureaux_siege5_page-0008.jpg"
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?=htmlspecialchars($econf['label'])?> · TUNISAIR</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --red:#C8102E;--red-dark:#9B0E23;
  --navy:#0F2563;--navy-mid:#1D4ED8;
  --ink:#1A1A18;--muted:#6B7280;
  --bg:#F4F6F9;--white:#fff;
  --rule:rgba(0,0,0,.07);--shadow:0 4px 20px rgba(0,0,0,.07);
  --ac:<?=$c1ea?>;--ac2:<?=$c2ea?>;
}
html,body{height:100%;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);overflow:hidden;}
::-webkit-scrollbar{width:5px;height:5px;}::-webkit-scrollbar-track{background:var(--bg);}::-webkit-scrollbar-thumb{background:rgba(0,0,0,.15);border-radius:4px;}::-webkit-scrollbar-thumb:hover{background:rgba(0,0,0,.25);}
.navbar{background:var(--white);border-bottom:3px solid var(--red);box-shadow:0 2px 10px rgba(0,0,0,.06);height:64px;padding:0 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:200;flex-shrink:0;}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.nav-logo{height:38px;width:auto;max-width:110px;object-fit:contain;}
.nav-brand-text{font-size:14px;font-weight:700;color:var(--red);}
.nav-bc{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);}
.nav-bc a{color:var(--muted);text-decoration:none;}.nav-bc a:hover{color:var(--red);}
.nav-bc .sep{opacity:.4;}.nav-bc strong{color:var(--ink);font-weight:600;}
.nav-right{display:flex;align-items:center;gap:14px;}
.nav-user{font-size:13px;font-weight:500;color:var(--muted);}
.btn-deconnexion{background:var(--red);color:white;padding:7px 18px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:600;transition:all .2s;}
.btn-deconnexion:hover{background:var(--red-dark);}
.app-wrap{display:flex;flex-direction:column;height:100vh;}
.app{display:flex;flex:1;overflow:hidden;min-height:0;}
.col1{width:220px;min-width:220px;background:var(--white);border-right:1.5px solid var(--rule);display:flex;flex-direction:column;overflow:hidden;box-shadow:2px 0 8px rgba(0,0,0,.04);}
.c1-top{padding:12px;border-bottom:1.5px solid var(--rule);}
.c1-lbl{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:7px;}
.c1-search{position:relative;}
.c1-search svg{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none;}
.c1-search input{width:100%;padding:8px 10px 8px 28px;background:var(--bg);border:1.5px solid var(--rule);border-radius:9px;font-size:12px;font-family:inherit;color:var(--ink);outline:none;transition:border-color .2s;}
.c1-search input:focus{border-color:var(--ac);}
.c1-search input::placeholder{color:var(--muted);}
.floor-list{flex:1;min-height:0;overflow-y:auto;padding:6px;}
.fi{display:flex;align-items:center;gap:9px;padding:9px 10px;border-radius:10px;cursor:pointer;transition:background .1s;margin-bottom:2px;}
.fi:hover{background:var(--bg);}.fi.active{background:rgba(0,0,0,.04);}
.fi-badge{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:white;flex-shrink:0;}
.fi-info{flex:1;min-width:0;}.fi-name{font-size:12px;font-weight:600;color:var(--ink);}.fi.active .fi-name{color:var(--ac);}
.fi-sub{font-size:10px;color:var(--muted);margin-top:1px;}.fi-n{font-size:12px;font-weight:700;color:var(--muted);flex-shrink:0;}.fi.active .fi-n{color:var(--ac);}

/* ── Conflict badge on floor list ── */
.fi-conflict{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#C8102E;color:white;font-size:8px;font-weight:800;flex-shrink:0;}

.col2{width:305px;min-width:305px;background:var(--bg);border-right:1.5px solid var(--rule);display:flex;flex-direction:column;overflow:hidden;}
.c2-top{padding:12px;background:var(--white);border-bottom:1.5px solid var(--rule);flex-shrink:0;box-shadow:0 2px 6px rgba(0,0,0,.04);}
.c2-name{font-size:14px;font-weight:700;color:var(--navy);margin-bottom:1px;}
.c2-sub{font-size:11px;color:var(--muted);margin-bottom:8px;}
.c2-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:5px;margin-bottom:6px;}
.c2-stat{background:var(--bg);border-radius:7px;padding:6px 7px;text-align:center;border:1.5px solid var(--rule);}
.c2-stat.clickable{cursor:pointer;transition:all .15s;user-select:none;}
.c2-stat.clickable:hover{border-color:var(--ac);box-shadow:0 2px 8px rgba(0,0,0,.08);transform:translateY(-1px);}
.c2-stat.active-stat{border-color:var(--ac)!important;background:#fff;}
.c2-sv{font-size:13px;font-weight:700;color:var(--navy);}.c2-sl{font-size:9px;color:var(--muted);margin-top:1px;}
.hn-bar{display:flex;align-items:center;justify-content:space-between;padding:7px 11px;border-radius:8px;border:1.5px solid #FDE68A;background:#FFFBEB;margin-bottom:6px;cursor:pointer;user-select:none;transition:all .15s;}
.hn-bar:hover{background:#FEF3C7;}.hn-bar.active-stat{border-color:#D97706!important;background:#FEF3C7;}
.hn-bar-left{display:flex;align-items:center;gap:6px;}
.hn-bar-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#D97706;}
.hn-bar-count{background:#DC2626;color:white;font-size:10px;font-weight:700;padding:1px 8px;border-radius:10px;}
.stat-dropdown{display:none;margin-bottom:7px;border-radius:9px;overflow:hidden;border:1.5px solid var(--rule);background:var(--white);box-shadow:0 6px 20px rgba(0,0,0,.1);}
.stat-dropdown.open{display:block;animation:ddIn .15s ease;}
@keyframes ddIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
.stat-dd-hdr{display:flex;align-items:center;justify-content:space-between;padding:8px 11px;background:var(--bg);border-bottom:1px solid var(--rule);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;}
.stat-dd-close{cursor:pointer;color:var(--muted);font-size:14px;line-height:1;padding:0 3px;}
.stat-dd-list{max-height:200px;overflow-y:auto;display:flex;flex-direction:column;}
.stat-dd-item{display:flex;align-items:center;gap:8px;padding:8px 11px;cursor:pointer;border-bottom:1px solid var(--rule);transition:background .1s;}
.stat-dd-item:last-child{border-bottom:none;}.stat-dd-item:hover{background:var(--bg);}
.stat-dd-ref{font-family:monospace;font-weight:700;font-size:10px;flex-shrink:0;min-width:36px;}
.stat-dd-name{flex:1;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--ink);}
.stat-dd-val{font-family:monospace;font-size:10px;font-weight:700;flex-shrink:0;}
.stat-dd-empty{padding:16px;text-align:center;font-size:12px;color:var(--muted);}
.c2-sw{position:relative;margin-bottom:7px;}
.c2-sw svg{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none;}
.c2-input{width:100%;padding:8px 10px 8px 28px;background:var(--white);border:1.5px solid var(--rule);border-radius:9px;font-size:12px;font-family:inherit;color:var(--ink);outline:none;transition:border-color .2s;}
.c2-input:focus{border-color:var(--ac);}
.c2-input::placeholder{color:var(--muted);}
.c2-dir{width:100%;padding:7px 10px;background:var(--white);border:1.5px solid var(--rule);border-radius:9px;font-size:12px;font-family:inherit;color:var(--ink);outline:none;cursor:pointer;margin-bottom:7px;}
.c2-dir:focus{border-color:var(--ac);}
.c2-add{width:100%;padding:9px;color:white;border:none;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:5px;transition:opacity .2s;background:linear-gradient(135deg,<?=$econf['color']?>);}
.c2-add:hover{opacity:.88;}
.c2-plan-btn{width:100%;padding:8px;background:var(--white);border:1.5px solid var(--rule);border-radius:9px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:6px;color:var(--muted);transition:all .2s;margin-top:6px;}
.c2-plan-btn:hover{border-color:var(--ac);color:var(--ac);}
.c2-list{flex:1;min-height:0;overflow-y:auto;padding:7px;}
.bcard{background:var(--white);border:1.5px solid transparent;border-radius:12px;padding:11px 13px;cursor:pointer;transition:all .15s;margin-bottom:5px;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.bcard:hover{border-color:var(--rule);box-shadow:0 4px 12px rgba(0,0,0,.08);}
.bcard.sel{border-color:transparent;box-shadow:0 4px 16px rgba(0,0,0,.1);}
.bcard.hn-c{border-color:#FDE68A;}.bcard.sel.hn-c{border-color:transparent;}
.bcard.conflict-c{border-color:#FECACA;}.bcard.sel.conflict-c{border-color:transparent;}
.bc-top{display:flex;align-items:center;gap:6px;margin-bottom:4px;}
.bc-ref{font-family:monospace;font-size:10px;font-weight:700;padding:2px 7px;border-radius:5px;}
.bc-st{font-size:10px;font-weight:600;padding:2px 7px;border-radius:5px;margin-left:auto;}
.bc-st.occ{background:#DCFCE7;color:#15803D;}.bc-st.lib{background:#FEE2E2;color:#DC2626;}.bc-st.dep{background:#FEF3C7;color:#D97706;}
.bc-hn{font-size:9px;font-weight:700;padding:1px 5px;border-radius:4px;background:#FEF3C7;color:#D97706;}
.bc-conflict{font-size:9px;font-weight:700;padding:1px 5px;border-radius:4px;background:#FEE2E2;color:#C8102E;}
.bc-name{font-size:12px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.bc-meta{display:flex;align-items:center;gap:7px;margin-top:3px;font-size:11px;color:var(--muted);}
.bc-mle{font-family:monospace;font-size:10px;font-weight:600;color:var(--navy);}
.bc-dir-t{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px;}
.bc-m2{margin-left:auto;font-family:monospace;font-size:10px;}
.no-res{padding:32px 14px;text-align:center;color:var(--muted);font-size:13px;}
.col3{flex:1;min-width:0;overflow-y:auto;background:var(--bg);}
.empty-det{height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;color:var(--muted);padding:40px;text-align:center;}
.empty-det svg{opacity:.25;}.empty-det p{font-size:14px;line-height:1.7;max-width:280px;}
.dw{padding:24px 28px 60px;max-width:720px;}
@keyframes slideIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.dw{animation:slideIn .18s ease;}
.dh{background:var(--white);border-radius:16px;border:1.5px solid var(--rule);padding:20px 22px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;box-shadow:var(--shadow);position:relative;overflow:hidden;}
.dh::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,<?=$econf['color']?>);}
.dh-bc{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--muted);margin-bottom:6px;}
.dh-title{font-size:20px;font-weight:700;color:var(--navy);letter-spacing:-.2px;}
.dh-sub{font-size:12px;color:var(--muted);margin-top:3px;}
.dh-badges{display:flex;align-items:center;gap:7px;margin-top:8px;flex-wrap:wrap;}
.dh-st{font-size:11px;font-weight:700;padding:4px 11px;border-radius:20px;}
.dh-st.occ{background:#DCFCE7;color:#15803D;border:1px solid #BBF7D0;}
.dh-st.lib{background:#FEE2E2;color:#DC2626;border:1px solid #FECACA;}
.dh-st.dep{background:#FEF3C7;color:#D97706;border:1px solid #FDE68A;}
.dh-hn{background:#FEF3C7;color:#D97706;border:1px solid #FDE68A;font-size:11px;font-weight:700;padding:4px 11px;border-radius:20px;}
.dh-actions{display:flex;gap:7px;flex-shrink:0;flex-wrap:wrap;}
.sec{background:var(--white);border-radius:13px;padding:17px 19px;margin-bottom:12px;border:1.5px solid var(--rule);box-shadow:var(--shadow);}
.sec.sec-hn{border-color:#FDE68A;background:#FFFDF5;}
.sec.sec-decision{border-color:#BBF7D0;background:#F0FDF4;}
.sec-t{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:12px;padding-bottom:9px;border-bottom:1.5px solid var(--rule);}
.fg{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.f{background:var(--bg);border-radius:9px;padding:9px 12px;}.f.full{grid-column:1/-1;}.f.hi{background:rgba(109,40,217,.06);border:1px solid rgba(109,40,217,.15);}
.fl{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:2px;}
.fv{font-size:13px;font-weight:600;color:var(--ink);}
.occ-p{display:flex;align-items:center;gap:13px;padding:12px;background:var(--bg);border-radius:10px;}
.occ-av{width:46px;height:46px;border-radius:12px;display:grid;place-items:center;font-size:17px;font-weight:700;color:white;flex-shrink:0;}
.occ-name{font-size:14px;font-weight:700;color:var(--navy);}.occ-emp{font-size:12px;color:var(--muted);margin-top:1px;}
.occ-mle{display:inline-block;margin-top:5px;font-family:monospace;font-size:11px;font-weight:700;padding:2px 8px;border-radius:5px;}
.surf-bar{margin-top:8px;}.sb-track{height:6px;background:var(--rule);border-radius:3px;overflow:hidden;}
.sb-fill{height:100%;border-radius:3px;}.sb-fill.ok{background:#22C55E;}.sb-fill.over{background:#F59E0B;}.sb-fill.under{background:#EF4444;}
.sb-lbls{display:flex;justify-content:space-between;font-size:10px;color:var(--muted);margin-top:4px;}
.sb-diff{font-size:12px;font-weight:700;margin-top:4px;}.sb-diff.pos{color:#15803D;}.sb-diff.neg{color:#DC2626;}.sb-diff.neu{color:var(--muted);}
.na{display:flex;align-items:flex-start;gap:7px;padding:10px 13px;border-radius:9px;font-size:12px;font-weight:600;margin-top:9px;line-height:1.5;}
.na.ok{background:#DCFCE7;color:#15803D;border:1px solid #BBF7D0;}.na.over{background:#FEE2E2;color:#DC2626;border:1px solid #FECACA;}.na.warn{background:#FEF3C7;color:#D97706;border:1px solid #FDE68A;}
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:none;font-family:inherit;transition:all .15s;text-decoration:none;}
.btn-p{color:white;background:linear-gradient(135deg,<?=$econf['color']?>);}.btn-p:hover{opacity:.88;}
.btn-g{background:transparent;color:var(--muted);border:1.5px solid var(--rule);}.btn-g:hover{background:var(--bg);color:var(--ink);}
.btn-d{background:#FEE2E2;color:#DC2626;border:1px solid #FECACA;}.btn-d:hover{background:#FECACA;}
.btn-transfer{background:linear-gradient(135deg,#0F2563,#1D4ED8);color:white;border:none;}.btn-transfer:hover{opacity:.88;}
.btn-sm{padding:5px 10px;font-size:11px;}

/* ══ CONFLIT BUREAU ══ */
.conflit-banner{display:flex;align-items:flex-start;gap:11px;background:#FEF2F2;border:0.5px solid #F09595;border-left:3px solid #C8102E;border-radius:13px;padding:14px 16px;margin-bottom:14px;animation:slideIn .2s ease;}
.conflit-body{flex:1;}
.conflit-title{font-size:12px;font-weight:700;color:#791F1F;margin-bottom:9px;display:flex;align-items:center;gap:7px;}
.conflit-agents{display:flex;flex-direction:column;gap:6px;}
.conflit-agent{display:flex;align-items:center;gap:9px;padding:8px 12px;background:white;border:0.5px solid #F09595;border-radius:9px;}
.conf-av{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:white;flex-shrink:0;}
.conf-info{flex:1;min-width:0;}
.conf-name{font-size:12px;font-weight:600;color:#791F1F;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.conf-meta{font-size:10px;color:#A32D2D;opacity:.85;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.conf-tag{font-size:10px;padding:2px 9px;border-radius:20px;background:#FEE2E2;color:#A32D2D;font-weight:700;white-space:nowrap;flex-shrink:0;}
.conflit-footer{margin-top:10px;font-size:11px;color:#A32D2D;line-height:1.6;padding-top:9px;border-top:0.5px solid #FECACA;}

/* Décision PDF card */
.dec-card{display:flex;align-items:center;gap:12px;background:var(--bg);border-radius:10px;padding:11px 14px;margin-bottom:8px;}
.dec-icon{width:40px;height:40px;border-radius:10px;background:#DCFCE7;display:grid;place-items:center;flex-shrink:0;}
.dec-info{flex:1;min-width:0;}
.dec-name{font-size:12px;font-weight:700;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.dec-sub{font-size:10px;color:var(--muted);margin-top:2px;}
.dec-actions{display:flex;gap:6px;flex-shrink:0;}
.dec-upload-zone{border:2px dashed var(--rule);border-radius:10px;padding:20px;text-align:center;cursor:pointer;transition:all .2s;}
.dec-upload-zone:hover{border-color:var(--ac);background:rgba(0,0,0,.02);}
.dec-upload-zone input{display:none;}
.dec-upload-lbl{font-size:12px;color:var(--muted);margin-top:6px;}

/* Modal styles */
.modal-bg{display:none;position:fixed;inset:0;z-index:400;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);align-items:center;justify-content:center;}
.modal-bg.open{display:flex;}
.mi{background:var(--white);border-radius:18px;width:640px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.2);}
.mi h2{font-size:17px;font-weight:700;color:var(--navy);padding:20px 24px 0;}
.di{background:var(--white);border-radius:14px;padding:26px;width:360px;max-width:94vw;text-align:center;box-shadow:0 16px 60px rgba(0,0,0,.18);}
.di h3{font-size:16px;font-weight:700;color:var(--navy);margin-bottom:8px;}
.di p{font-size:13px;color:var(--muted);margin-bottom:20px;line-height:1.6;}
.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:11px;padding:16px 24px;}
.fg-g{display:flex;flex-direction:column;gap:4px;}.fg-g.full{grid-column:1/-1;}
.fg-sec{grid-column:1/-1;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);padding:4px 0 2px;border-bottom:1.5px solid var(--rule);margin-top:4px;}
.fg-lbl{font-size:12px;font-weight:600;color:var(--ink);}
.fg-inp{padding:9px 11px;background:var(--bg);border:1.5px solid var(--rule);border-radius:9px;font-size:13px;font-family:inherit;color:var(--ink);outline:none;transition:border-color .2s;}
.fg-inp:focus{border-color:var(--ac);}
.fg-inp[readonly]{background:#f9f9f9;color:var(--muted);}
.fg-actions{padding:0 24px 20px;display:flex;gap:8px;justify-content:flex-end;}

/* Transfer modal */
.tr-tabs{display:flex;gap:0;border-bottom:1.5px solid var(--rule);margin:0 24px;}
.tr-tab{padding:10px 16px;font-size:12px;font-weight:600;cursor:pointer;color:var(--muted);border-bottom:2px solid transparent;margin-bottom:-1.5px;transition:all .15s;}
.tr-tab.active{color:var(--ac);border-bottom-color:var(--ac);}
.tr-panel{display:none;padding:16px 24px;}.tr-panel.active{display:block;}
.pdf-prev-wrap{background:#f5f5f5;border-radius:10px;padding:14px;text-align:center;margin-top:10px;}
.pdf-prev-wrap iframe{width:100%;height:320px;border:none;border-radius:6px;}

.libres-hint{margin-top:5px;font-size:10px;font-weight:600;padding:4px 8px;border-radius:6px;background:#EFF6FF;color:#0369A1;border:1px solid #BFDBFE;display:flex;align-items:center;gap:5px;}
.plan-etage-panel{padding:20px 24px 32px;}
.pe-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;}
.pe-title{font-size:18px;font-weight:700;color:var(--navy);}.pe-sub{font-size:13px;color:var(--muted);margin-top:3px;}
.pe-badge{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;padding:4px 12px;border-radius:20px;color:white;}
.pe-actions{display:flex;gap:8px;align-items:center;}
.pe-upload-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:inherit;transition:opacity .2s;color:white;}
.pe-upload-btn:hover{opacity:.88;}.pe-upload-input{display:none;}
.pe-img-wrap{border-radius:14px;overflow:hidden;border:1.5px solid var(--rule);background:var(--white);box-shadow:var(--shadow);position:relative;}
.pe-img-wrap img{width:100%;display:block;cursor:zoom-in;transition:transform .2s;}.pe-img-wrap img:hover{transform:scale(1.005);}
.pe-img-bar{display:flex;align-items:center;justify-content:space-between;padding:10px 16px;background:var(--bg);border-top:1.5px solid var(--rule);font-size:12px;color:var(--muted);}
.pe-placeholder{border-radius:14px;border:2px dashed var(--rule);background:var(--white);padding:60px 40px;text-align:center;box-shadow:var(--shadow);}
.pe-ph-icon{width:64px;height:64px;border-radius:16px;margin:0 auto 16px;display:grid;place-items:center;}
.pe-ph-title{font-size:16px;font-weight:700;color:var(--navy);margin-bottom:8px;}
.pe-ph-sub{font-size:13px;color:var(--muted);line-height:1.7;margin-bottom:20px;}
.pe-ph-btn{display:inline-flex;align-items:center;gap:7px;padding:11px 22px;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:inherit;transition:opacity .2s;color:white;}
.pe-ph-btn:hover{opacity:.88;}
.lightbox{display:none;position:fixed;inset:0;z-index:600;background:rgba(0,0,0,.9);align-items:center;justify-content:center;cursor:zoom-out;}
.lightbox.open{display:flex;}
.lightbox img{max-width:92vw;max-height:90vh;border-radius:10px;}
.lb-close{position:absolute;top:16px;right:16px;width:34px;height:34px;border-radius:8px;background:rgba(255,255,255,.15);border:none;cursor:pointer;display:grid;place-items:center;color:white;}
mark{background:#FEF3C7;border-radius:3px;padding:0 1px;}
/* ── Clé de bureau ── */
.cle-section{margin-top:0;}
.cle-card{display:flex;align-items:center;gap:13px;padding:13px 15px;border-radius:12px;border:1.5px solid var(--rule);background:var(--white);margin-top:8px;}
.cle-icon{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;flex-shrink:0;font-size:20px;}
.cle-info{flex:1;min-width:0;}
.cle-title{font-size:13px;font-weight:700;color:var(--ink);}
.cle-sub{font-size:11px;color:var(--muted);margin-top:2px;}
.cle-actions{display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap;}
.btn-cle-yes{background:linear-gradient(135deg,#15803D,#22C55E);color:white;border:none;}
.btn-cle-yes:hover{opacity:.88;}
.btn-cle-prise{background:linear-gradient(135deg,#0F2563,#1D4ED8);color:white;border:none;}
.btn-cle-prise:hover{opacity:.88;}
.btn-cle-reset{background:#F3F4F6;color:#6B7280;border:1px solid var(--rule);}
.btn-cle-reset:hover{background:#E5E7EB;}
.cle-confirm-box{background:#F0FDF4;border:1.5px solid #BBF7D0;border-radius:10px;padding:12px 14px;margin-top:8px;display:none;}
.cle-confirm-box.show{display:block;}
.cle-confirm-box .cle-confirm-q{font-size:13px;font-weight:600;color:#15803D;margin-bottom:10px;}
.cle-confirm-box.danger{background:#FEF2F2;border-color:#FECACA;}
.cle-confirm-box.danger .cle-confirm-q{color:#DC2626;}
.cle-prise-box{background:#EFF6FF;border:1.5px solid #BFDBFE;border-radius:10px;padding:12px 14px;margin-top:8px;display:none;}
.cle-prise-box.show{display:block;}
.cle-prise-box .cle-confirm-q{font-size:13px;font-weight:600;color:#1D4ED8;margin-bottom:8px;}
.spin{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.3);border-top-color:white;border-radius:50%;animation:spin .7s linear infinite;}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Notification popup ── */
#notifOverlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;display:flex;align-items:center;justify-content:center;animation:fadeInOv .2s ease;}
@keyframes fadeInOv{from{opacity:0}to{opacity:1}}
#notifModal{background:#fff;border-radius:16px;width:600px;max-width:95vw;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.18);overflow:hidden;animation:slideUp .25s ease;}
@keyframes slideUp{from{transform:translateY(16px);opacity:0}to{transform:translateY(0);opacity:1}}
#notifModal .nm-head{padding:18px 20px 14px;border-bottom:1.5px solid var(--rule);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
#notifModal .nm-title{font-size:15px;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:8px;}
#notifModal .nm-close{background:none;border:none;cursor:pointer;color:var(--muted);font-size:20px;line-height:1;padding:4px 8px;border-radius:6px;transition:background .15s;}
#notifModal .nm-close:hover{background:var(--bg);}
#notifModal .nm-tabs{display:flex;border-bottom:1.5px solid var(--rule);flex-shrink:0;padding:0 8px;}
#notifModal .nm-tab{background:none;border:none;border-bottom:2px solid transparent;padding:10px 16px;font-size:12px;font-weight:600;cursor:pointer;color:var(--muted);transition:all .15s;margin-bottom:-1.5px;}
#notifModal .nm-tab.active{color:var(--red);border-bottom-color:var(--red);}
#notifModal .nm-body{flex:1;overflow-y:auto;padding:12px 16px;}
#notifModal .nm-empty{text-align:center;padding:32px;color:var(--muted);font-size:13px;}
#notifModal .nm-item{display:flex;gap:12px;align-items:flex-start;padding:10px 12px;border-radius:10px;margin-bottom:6px;border:1.5px solid transparent;}
#notifModal .nm-item.danger{background:#FFF5F5;border-color:#FED7D7;}
#notifModal .nm-item.warning{background:#FFFBEB;border-color:#FDE68A;}
#notifModal .nm-item.info{background:#EFF6FF;border-color:#BFDBFE;}
#notifModal .nm-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:15px;}
#notifModal .nm-item.danger .nm-icon{background:#FED7D7;color:#C53030;}
#notifModal .nm-item.warning .nm-icon{background:#FDE68A;color:#92400E;}
#notifModal .nm-item.info .nm-icon{background:#BFDBFE;color:#1D4ED8;}
#notifModal .nm-item-title{font-size:12px;font-weight:700;margin-bottom:2px;}
#notifModal .nm-item.danger .nm-item-title{color:#C53030;}
#notifModal .nm-item.warning .nm-item-title{color:#92400E;}
#notifModal .nm-item.info .nm-item-title{color:#1D4ED8;}
#notifModal .nm-item-msg{font-size:12px;color:var(--muted);line-height:1.5;}
#notifModal .nm-foot{padding:14px 20px;border-top:1.5px solid var(--rule);display:flex;justify-content:space-between;align-items:center;flex-shrink:0;}
#notifModal .nm-foot-note{font-size:11px;color:var(--muted);}
.notif-bell-btn{position:relative;background:none;border:1.5px solid var(--rule);border-radius:9px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--muted);transition:all .2s;}
.notif-bell-btn:hover{border-color:var(--red);color:var(--red);}
.notif-bell-badge{position:absolute;top:-5px;right:-5px;background:var(--red);color:white;font-size:9px;font-weight:800;min-width:16px;height:16px;border-radius:8px;display:flex;align-items:center;justify-content:center;padding:0 3px;border:2px solid white;}
</style>
</head>
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<body>
<div class="app-wrap">
<nav class="navbar">
  <div style="display:flex;align-items:center;gap:14px;">
    <a href="index.php" class="nav-brand">
      <img src="logo.webp" alt="TUNISAIR" class="nav-logo">
      <span class="nav-brand-text">TUNISAIR — Patrimoine</span>
    </a>
    <span style="opacity:.3;font-size:18px;">|</span>
    <nav class="nav-bc">
      <a href="dashboard.php">Accueil</a><span class="sep">›</span>
      <a href="siege.php">Siège Social</a><span class="sep">›</span>
      <strong id="navLabel"><?=htmlspecialchars($econf['label'])?></strong>
    </nav>
  </div>
  <div class="nav-right">
    <button class="notif-bell-btn" id="notifBellBtn" onclick="openNotifModal()" title="Notifications" aria-label="Ouvrir les notifications">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
      <span class="notif-bell-badge" id="notifBellCount" style="display:none">0</span>
    </button>
    <span class="nav-user"><?=htmlspecialchars($username)?></span>
    <a href="logout.php" class="btn-deconnexion">Déconnexion</a>
  </div>
</nav>
<div class="app">

<!-- COL 1 -->
<div class="col1">
  <div class="c1-top">
    <div class="c1-lbl">Recherche globale</div>
    <div class="c1-search">
      <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M15 15l3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      <input type="text" id="gSearch" placeholder="MLE, nom, direction…" oninput="globalSearch(this.value)">
    </div>
  </div>
  <div class="floor-list" id="floorList">
    <?php foreach($ETAGES as $n=>$e): $s=$stats[$n]; $pct=$s['total']>0?round($s['occupes']/$s['total']*100):0; $c1f=explode(',',$e['color'])[0]; ?>
    <div class="fi <?=$n===$ea?'active':''?>" id="fi<?=$n?>" onclick="switchFloor(<?=$n?>,true)">
      <div class="fi-badge" style="background:<?=$c1f?>"><?=htmlspecialchars($e['short'])?></div>
      <div class="fi-info">
        <div class="fi-name"><?=htmlspecialchars($e['label'])?></div>
        <div class="fi-sub"><?=$s['total']?> bur. · <?=$pct?>% occ.</div>
      </div>
      <div class="fi-n" id="fi-n-<?=$n?>"><?=$s['total']?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- COL 2 -->
<div class="col2">
  <div class="c2-top">
    <div class="c2-name" id="c2Name"><?=htmlspecialchars($econf['label'])?></div>
    <div class="c2-sub" id="c2Sub"><?=htmlspecialchars($econf['sub'])?></div>
    <div class="c2-stats" id="c2Stats">
      <?php $s=$stats[$ea]; ?>
      <div class="c2-stat"><div class="c2-sv"><?=$s['total']?></div><div class="c2-sl">Total</div></div>
      <div class="c2-stat"><div class="c2-sv" style="color:#15803D"><?=$s['occupes']?></div><div class="c2-sl">Occ.</div></div>
      <div class="c2-stat clickable" id="statLibre" onclick="toggleDD('libre')">
        <div class="c2-sv" style="color:#0369A1"><?=$s['libres']?></div>
        <div class="c2-sl" style="color:#0369A1">Libres ▾</div>
      </div>
    </div>
    <div class="hn-bar" id="statHN" onclick="toggleDD('hn')">
      <div class="hn-bar-left">
        <svg width="11" height="11" viewBox="0 0 20 20" fill="none"><path d="M10 3l7.5 14H2.5z" stroke="#D97706" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 9v4M10 15h.01" stroke="#D97706" stroke-width="1.5" stroke-linecap="round"/></svg>
        <span class="hn-bar-lbl">Hors norme</span>
      </div>
      <div style="display:flex;align-items:center;gap:5px;">
        <span class="hn-bar-count" id="hnCount"><?=$s['hn']?></span>
        <span style="font-size:10px;color:#D97706;font-weight:700;">▾</span>
      </div>
    </div>
    <div class="stat-dropdown" id="ddLibre">
      <div class="stat-dd-hdr">
        <span style="color:#0369A1;">Bureaux libres — <span id="ddLibreFloor"><?=htmlspecialchars($econf['label'])?></span></span>
        <span class="stat-dd-close" onclick="closeDD('libre')">✕</span>
      </div>
      <div class="stat-dd-list" id="ddLibreList"></div>
    </div>
    <div class="stat-dropdown" id="ddHN">
      <div class="stat-dd-hdr">
        <span style="color:#D97706;">⚠ Hors norme — <span id="ddHNFloor"><?=htmlspecialchars($econf['label'])?></span></span>
        <span class="stat-dd-close" onclick="closeDD('hn')">✕</span>
      </div>
      <div class="stat-dd-list" id="ddHNList"></div>
    </div>
    <div class="c2-sw">
      <svg width="12" height="12" viewBox="0 0 20 20" fill="none"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M15 15l3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      <input type="text" class="c2-input" id="listSearch" placeholder="MLE, nom, réf. bureau…" oninput="filterList(this.value,document.getElementById('dirFilter').value)">
    </div>
    <select class="c2-dir" id="dirFilter" onchange="filterList(document.getElementById('listSearch').value,this.value)">
      <option value="">— Toutes les directions —</option>
    </select>
    <?php if($canCreate): ?>
    <button class="c2-add" onclick="openAdd()">
      <svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M2 8h12" stroke="white" stroke-width="2.2" stroke-linecap="round"/></svg>
      Ajouter un bureau
    </button>
    <?php endif; ?>
    <button class="c2-plan-btn" id="c2PlanBtn" onclick="showPlanEtage()">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M3 9h18M9 9v12M3 15h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      Voir le plan de l'étage
    </button>
  </div>
  <div class="c2-list" id="c2List"></div>
</div>

<!-- COL 3 -->
<div class="col3">
  <div class="empty-det" id="emptyDet">
    <svg width="52" height="52" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.1"/><path d="M3 9h18M9 9v12" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/></svg>
    <p>Sélectionnez un bureau pour afficher ses informations.</p>
    <button onclick="showPlanEtage()" style="display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:10px;border:1.5px solid var(--rule);background:var(--white);color:var(--muted);font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .2s;" onmouseover="this.style.borderColor='<?=$c1ea?>';this.style.color='<?=$c1ea?>'" onmouseout="this.style.borderColor='rgba(0,0,0,.07)';this.style.color='#6B7280'">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M3 9h18M9 9v12M3 15h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      Afficher le plan de l'étage
    </button>
  </div>
  <div id="planEtagePanel" style="display:none;"><div class="plan-etage-panel" id="planEtageContent"></div></div>
  <div id="detCont" style="display:none;"></div>
</div>
</div>
</div>

<!-- ══════════════ MODALS ══════════════ -->

<!-- ADD MODAL -->
<div class="modal-bg" id="addModal">
  <div class="mi">
    <h2>Nouveau bureau — <span id="addFloorLbl" style="color:<?=$c1ea?>"></span></h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="etage" id="addEtage" value="<?=$ea?>">
      <div class="fgrid">
        <div class="fg-sec">Identification</div>
        <div class="fg-g"><label class="fg-lbl">Réf. Bureau *</label><input class="fg-inp" type="text" name="ref_bureau" required></div>
        <div class="fg-g"><label class="fg-lbl">Statut</label><select class="fg-inp" name="statut"><option value="Occupé">Occupé</option><option value="Libre">Libre</option><option value="En travaux">En travaux</option><option value="Dépôt">Dépôt</option></select></div>
        <div class="fg-g"><label class="fg-lbl">Superficie réelle (m²)</label><input class="fg-inp" type="number" step="0.01" name="superficie"></div>
        <div class="fg-g"><label class="fg-lbl">Surface de droit (m²)</label><input class="fg-inp" type="number" step="0.01" name="superficie_droit"></div>
        <div class="fg-sec">Occupant</div>
        <div class="fg-g"><label class="fg-lbl">Matricule (MLE)</label><input class="fg-inp" type="text" name="mle" style="font-family:monospace"></div>
        <div class="fg-g"><label class="fg-lbl">Nom</label><input class="fg-inp" type="text" name="nom"></div>
        <div class="fg-g"><label class="fg-lbl">Prénom</label><input class="fg-inp" type="text" name="prenom"></div>
        <div class="fg-g full"><label class="fg-lbl">Emploi</label><input class="fg-inp" type="text" name="emploi"></div>
        <div class="fg-g full"><label class="fg-lbl">Fonction (détermine la norme)</label>
          <select class="fg-inp" name="l_fonct">
            <option value="">— Sélectionner —</option>
            <?php foreach($NORMES as $poste => $m2): ?>
            <option value="<?=htmlspecialchars($poste)?>"><?=htmlspecialchars($poste)?> (<?=$m2?> m²)</option>
            <?php endforeach; ?>
          </select></div>
        <div class="fg-sec">Direction</div>
        <div class="fg-g full"><label class="fg-lbl">Entité / Service</label><input class="fg-inp" type="text" name="l_entite"></div>
        <div class="fg-g full"><label class="fg-lbl">Direction</label><input class="fg-inp" type="text" name="direction"></div>
        <div class="fg-g full"><label class="fg-lbl">Direction Centrale</label><input class="fg-inp" type="text" name="direction_centrale"></div>
        <div class="fg-g"><label class="fg-lbl">Classification</label><input class="fg-inp" type="text" name="l_classf"></div>
        <div class="fg-g full"><label class="fg-lbl">Notes</label><textarea class="fg-inp" name="notes" rows="2" style="resize:vertical"></textarea></div>
      </div>
      <div class="fg-actions">
        <button type="button" class="btn btn-g" onclick="closeModal('addModal')">Annuler</button>
        <button type="submit" class="btn btn-p">Créer le bureau</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-bg" id="editModal">
  <div class="mi">
    <h2>Modifier le bureau</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="eId"><input type="hidden" name="etage" id="eEtage">
      <div class="fgrid">
        <div class="fg-sec">Identification</div>
        <div class="fg-g"><label class="fg-lbl">Réf. Bureau</label><input class="fg-inp" type="text" name="ref_bureau" id="e_ref_bureau"></div>
        <div class="fg-g"><label class="fg-lbl">Statut</label><select class="fg-inp" name="statut" id="e_statut"><option value="Occupé">Occupé</option><option value="Libre">Libre</option><option value="En travaux">En travaux</option><option value="Dépôt">Dépôt</option></select></div>
        <div class="fg-g"><label class="fg-lbl">Superficie réelle (m²)</label><input class="fg-inp" type="number" step="0.01" name="superficie" id="e_superficie"></div>
        <div class="fg-g"><label class="fg-lbl">Surface de droit (m²)</label><input class="fg-inp" type="number" step="0.01" name="superficie_droit" id="e_superficie_droit"></div>
        <div class="fg-sec">Occupant</div>
        <div class="fg-g"><label class="fg-lbl">Matricule (MLE)</label><input class="fg-inp" type="text" name="mle" id="e_mle" style="font-family:monospace"></div>
        <div class="fg-g"><label class="fg-lbl">Nom</label><input class="fg-inp" type="text" name="nom" id="e_nom"></div>
        <div class="fg-g"><label class="fg-lbl">Prénom</label><input class="fg-inp" type="text" name="prenom" id="e_prenom"></div>
        <div class="fg-g full"><label class="fg-lbl">Emploi</label><input class="fg-inp" type="text" name="emploi" id="e_emploi"></div>
        <div class="fg-g full"><label class="fg-lbl">Fonction</label>
          <select class="fg-inp" name="l_fonct" id="e_l_fonct">
            <option value="">— Sélectionner —</option>
            <?php foreach($NORMES as $poste => $m2): ?>
            <option value="<?=htmlspecialchars($poste)?>"><?=htmlspecialchars($poste)?> (<?=$m2?> m²)</option>
            <?php endforeach; ?>
          </select></div>
        <div class="fg-sec">Direction</div>
        <div class="fg-g full"><label class="fg-lbl">Entité / Service</label><input class="fg-inp" type="text" name="l_entite" id="e_l_entite"></div>
        <div class="fg-g full"><label class="fg-lbl">Direction</label><input class="fg-inp" type="text" name="direction" id="e_direction"></div>
        <div class="fg-g full"><label class="fg-lbl">Direction Centrale</label><input class="fg-inp" type="text" name="direction_centrale" id="e_direction_centrale"></div>
        <div class="fg-g"><label class="fg-lbl">Classification</label><input class="fg-inp" type="text" name="l_classf" id="e_l_classf"></div>
        <div class="fg-g full"><label class="fg-lbl">Notes</label><textarea class="fg-inp" name="notes" id="e_notes" rows="2" style="resize:vertical"></textarea></div>
      </div>
      <div class="fg-actions">
        <button type="button" class="btn btn-g" onclick="closeModal('editModal')">Annuler</button>
        <button type="submit" class="btn btn-p">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-bg" id="deleteModal">
  <div class="di">
    <h3>Supprimer ce bureau ?</h3>
    <p>Cette action est irréversible.<br><strong id="delLbl"></strong></p>
    <div style="display:flex;gap:9px;justify-content:center">
      <button class="btn btn-g" onclick="closeModal('deleteModal')">Annuler</button>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delId"><input type="hidden" name="etage" id="delEtage">
        <button type="submit" class="btn btn-d">Supprimer</button>
      </form>
    </div>
  </div>
</div>

<!-- TRANSFER MODAL -->
<div class="modal-bg" id="transferModal">
  <div class="mi" style="width:700px;">
    <h2 style="padding-bottom:6px;">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" style="vertical-align:-3px;margin-right:4px"><path d="M5 12h14M13 6l6 6-6 6" stroke="#0F2563" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Décision de Transfert — Bureau <span id="trOldRef" style="color:var(--ac)"></span>
    </h2>
    <div class="tr-tabs">
      <div class="tr-tab active" onclick="switchTrTab('infos')">① Informations</div>
      <div class="tr-tab" id="tabPdfGen" onclick="switchTrTab('gen')">② Générer PDF</div>
      <div class="tr-tab" onclick="switchTrTab('upload')">③ Importer PDF</div>
    </div>
    <div class="tr-panel active" id="trPanelInfos">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:11px;">
        <div class="fg-g"><label class="fg-lbl">Ancien bureau (actuel)</label><input class="fg-inp" type="text" id="tr_old_ref" readonly></div>
        <div class="fg-g">
          <label class="fg-lbl">Nouveau bureau *</label>
          <input class="fg-inp" type="text" id="tr_new_ref" placeholder="Ex: 2F6, 3E1…" list="libresDatalist" autocomplete="off" oninput="trUpdatePreview();trCheckNewBureau(this.value)">
          <datalist id="libresDatalist"></datalist>
          <div id="trLibresHint" class="libres-hint" style="display:none;"></div>
          <div id="trNewBureauInfo" style="display:none;margin-top:6px;padding:8px 11px;border-radius:8px;border:1.5px solid var(--rule);background:var(--bg);font-size:11px;"></div>
        </div>
        <div class="fg-g"><label class="fg-lbl">Matricule</label><input class="fg-inp" id="tr_mle" readonly style="font-family:monospace"></div>
        <div class="fg-g"><label class="fg-lbl">Prénom</label><input class="fg-inp" id="tr_prenom" readonly></div>
        <div class="fg-g"><label class="fg-lbl">Nom</label><input class="fg-inp" id="tr_nom" readonly></div>
        <div class="fg-g"><label class="fg-lbl">Direction</label><input class="fg-inp" id="tr_direction" readonly></div>
        <div style="grid-column:1/-1;border-top:1.5px solid var(--rule);padding-top:10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);">Statut après transfert</div>
        <div class="fg-g">
          <label class="fg-lbl">Ancien bureau <span style="font-family:monospace;color:var(--ac)" id="tr_old_ref_lbl"></span></label>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <button type="button" id="btnAncienLibre" onclick="setStatutAncien('Libre')" style="flex:1;padding:8px;border-radius:8px;border:1.5px solid #BBF7D0;background:#DCFCE7;color:#15803D;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;">✓ Marquer Libre</button>
            <button type="button" id="btnAncienConserver" onclick="setStatutAncien('')" style="flex:1;padding:8px;border-radius:8px;border:1.5px solid var(--rule);background:var(--bg);color:var(--muted);font-size:12px;font-weight:600;cursor:pointer;transition:all .15s;">Ne pas changer</button>
          </div>
          <div id="statut_ancien_lbl" style="font-size:10px;color:#15803D;margin-top:3px;font-weight:600;"></div>
          <input type="hidden" id="inp_statut_ancien" name="statut_ancien" value="Libre">
        </div>
        <div class="fg-g">
          <label class="fg-lbl">Nouveau bureau <span style="font-family:monospace;color:var(--ac)" id="tr_new_ref_lbl2"></span></label>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <button type="button" id="btnNouveauOccupe" onclick="setStatutNouveau('Occupé')" style="flex:1;padding:8px;border-radius:8px;border:1.5px solid #BFDBFE;background:#DBEAFE;color:#1D4ED8;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;">✓ Marquer Occupé</button>
            <button type="button" id="btnNouveauConserver" onclick="setStatutNouveau('')" style="flex:1;padding:8px;border-radius:8px;border:1.5px solid var(--rule);background:var(--bg);color:var(--muted);font-size:12px;font-weight:600;cursor:pointer;transition:all .15s;">Ne pas changer</button>
          </div>
          <div id="statut_nouveau_lbl" style="font-size:10px;color:#1D4ED8;margin-top:3px;font-weight:600;"></div>
          <input type="hidden" id="inp_statut_nouveau" name="statut_nouveau" value="Occupé">
        </div>
        <div style="grid-column:1/-1;border-top:1.5px solid var(--rule);padding-top:10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);">Paramètres de la décision</div>
        <div class="fg-g"><label class="fg-lbl">Date de la décision</label><input class="fg-inp" type="date" id="tr_date" oninput="trUpdatePreview()"></div>
        <div class="fg-g"><label class="fg-lbl">N° SG</label><input class="fg-inp" type="text" id="tr_sg_num" placeholder="Ex: 47" oninput="trUpdatePreview()"></div>
        <div class="fg-g" style="grid-column:1/-1"><label class="fg-lbl">Signataire</label><input class="fg-inp" type="text" id="tr_signataire" value="HAMZA LOUATI" oninput="trUpdatePreview()"></div>
      </div>
      <div style="margin-top:14px;display:flex;justify-content:flex-end;gap:8px;">
        <button class="btn btn-g" onclick="closeModal('transferModal')">Annuler</button>
        <button class="btn btn-g" onclick="submitTransferDirect()">
          Enregistrer sans PDF
        </button>
        <button class="btn btn-transfer" onclick="switchTrTab('gen');buildPDF()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" stroke="white" stroke-width="1.8"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" stroke="white" stroke-width="1.8" stroke-linecap="round"/></svg>
          Générer la décision PDF →
        </button>
      </div>
    </div>
    <div class="tr-panel" id="trPanelGen">
      <div style="margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <span style="font-size:13px;font-weight:600;color:var(--navy);">Aperçu de la décision générée</span>
        <div style="display:flex;gap:7px;">
          <button class="btn btn-g btn-sm" onclick="buildPDF()">↺ Actualiser</button>
          <button class="btn btn-g btn-sm" id="btnDownloadPrev" onclick="downloadPreviewPDF()">↓ Télécharger</button>
        </div>
      </div>
      <div class="pdf-prev-wrap" id="pdfPrevWrap"><div style="padding:40px;color:var(--muted);font-size:13px;">Cliquez sur "Générer" pour visualiser la décision.</div></div>
      <div style="margin-top:14px;display:flex;justify-content:flex-end;gap:8px;">
        <button class="btn btn-g" onclick="switchTrTab('infos')">← Retour</button>
        <button class="btn btn-transfer" id="btnSubmitGenPDF" onclick="submitTransferWithGenPDF()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M5 12l5 5L20 7" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Enregistrer le transfert + PDF
        </button>
      </div>
    </div>
    <div class="tr-panel" id="trPanelUpload">
      <p style="font-size:13px;color:var(--muted);margin-bottom:14px;line-height:1.6;">Importez un PDF de décision de transfert existant.</p>
      <label class="dec-upload-zone" for="trUploadFile" id="trDropZone">
        <input type="file" id="trUploadFile" accept=".pdf,.jpg,.jpeg,.png" onchange="trFileSelected(this)">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" style="margin:0 auto;display:block;opacity:.35"><path d="M12 16V8M9 11l3-3 3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M20 16.7A4 4 0 0018 9h-1.26A8 8 0 104 17.3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        <div class="dec-upload-lbl" id="trDropLabel">Cliquer ou glisser un fichier PDF / image ici</div>
      </label>
      <div id="trFilePreview" style="display:none;margin-top:10px;" class="pdf-prev-wrap">
        <iframe id="trFileIframe" style="width:100%;height:280px;border:none;border-radius:6px;"></iframe>
      </div>
      <div style="margin-top:14px;display:flex;justify-content:flex-end;gap:8px;">
        <button class="btn btn-g" onclick="switchTrTab('infos')">← Retour</button>
        <button class="btn btn-transfer" onclick="submitTransferWithUpload()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M5 12l5 5L20 7" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Enregistrer le transfert + PDF importé
        </button>
      </div>
    </div>
    <form method="post" enctype="multipart/form-data" id="transferForm" style="display:none;">
      <input type="hidden" name="action" value="transfer">
      <input type="hidden" name="etage" id="tr_etage_h">
      <input type="hidden" name="id" id="tr_id_h">
      <input type="hidden" name="old_ref_bureau" id="tr_old_h">
      <input type="hidden" name="new_ref_bureau" id="tr_new_h">
      <input type="hidden" name="pdf_base64" id="tr_pdf_b64_h">
    </form>
  </div>
</div>

<!-- LIGHTBOX -->
<div class="lightbox" id="lightbox" onclick="closeLB()">
  <button class="lb-close" onclick="closeLB()"><svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M3 3l10 10M13 3L3 13" stroke="white" stroke-width="1.8" stroke-linecap="round"/></svg></button>
  <img id="lbImg" src="" alt="">
</div>

<script>
const ALL=<?=json_encode(array_values($tous),JSON_UNESCAPED_UNICODE)?>;
const CAN_CREATE=<?=$canCreate?'true':'false'?>;
const CAN_UPDATE=<?=$canUpdate?'true':'false'?>;
const CAN_DELETE=<?=$canDelete?'true':'false'?>;
const NORMES=<?=json_encode($NORMES,JSON_UNESCAPED_UNICODE)?>;
const ECFG=<?=json_encode($ETAGES,JSON_UNESCAPED_UNICODE)?>;
const PLANS_ETAGE=<?=json_encode($plans_etage,JSON_UNESCAPED_UNICODE)?>;
const EMBEDDED_PLANS=<?=json_encode($embedded_plans,JSON_UNESCAPED_UNICODE)?>;
let curFloor=<?=$ea?>,activeId=null,openDD=null;
const byE={};ALL.forEach(b=>{const e=parseInt(b.etage);if(!byE[e])byE[e]=[];byE[e].push(b);});
const esc=s=>s?String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'):'';
const fmt=(v,d=2)=>v!=null&&v!==''?parseFloat(v).toLocaleString('fr-TN',{minimumFractionDigits:d,maximumFractionDigits:d}):'—';
const bclass=s=>{const l=(s||'').toLowerCase();return l.includes('occ')?'occ':l.includes('lib')?'lib':'dep';};
const isHN=b=>b.l_fonct&&NORMES[b.l_fonct]&&b.superficie&&(parseFloat(b.superficie)-NORMES[b.l_fonct])>2;
const isLibre=b=>{
  const st=(b.statut||'').toLowerCase();
  if(st==='libre') return true;
  if(st==='occupé'||st==='occupe'||st==='en travaux'||st==='dépôt'||st==='depot') return false;
  return !b.mle||!String(b.mle).trim();
};
const hl=(t,q)=>{if(!q||!t)return esc(t||'');return String(t).replace(new RegExp('('+q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi'),'<mark>$1</mark>');};

/* ── Détection conflits : même ref_bureau, id différent, occupant ── */
function getConflicts(b){
  if(!b.ref_bureau||!b.mle||!String(b.mle).trim()) return [];
  return ALL.filter(x=>
    String(x.id) !== String(b.id) &&
    x.ref_bureau &&
    x.ref_bureau.toLowerCase()===(b.ref_bureau||'').toLowerCase() &&
    x.mle && String(x.mle).trim()
  );
}

/* ── Compter les conflits par étage (pour badge dans la liste) ── */
function countConflictsForFloor(floorNum){
  const list=byE[floorNum]||[];
  const conflictRefs=new Set();
  const refGroups={};
  list.forEach(b=>{
    if(!b.ref_bureau||!b.mle||!String(b.mle).trim()) return;
    const ref=b.ref_bureau.toLowerCase();
    if(!refGroups[ref]) refGroups[ref]=[];
    refGroups[ref].push(b);
  });
  Object.values(refGroups).forEach(g=>{ if(g.length>1) g.forEach(b=>conflictRefs.add(b.ref_bureau.toLowerCase())); });
  return conflictRefs.size;
}

/* ── Mettre à jour les badges de conflit dans la liste d'étages ── */
function updateFloorConflictBadges(){
  Object.keys(ECFG).forEach(n=>{
    const count=countConflictsForFloor(parseInt(n));
    const fiN=document.getElementById('fi-n-'+n);
    if(!fiN) return;
    const existing=fiN.parentElement.querySelector('.fi-conflict');
    if(existing) existing.remove();
    if(count>0){
      const badge=document.createElement('span');
      badge.className='fi-conflict';
      badge.title=count+' conflit(s) d\'affectation';
      badge.textContent=count;
      fiN.parentElement.insertBefore(badge,fiN);
    }
  });
}

/* ────────── Dropdown helpers ────────── */
function toggleDD(type){
  if(openDD===type){closeDD(type);return;}
  if(openDD)closeDD(openDD);
  openDD=type;
  document.getElementById(type==='hn'?'ddHN':'ddLibre').classList.add('open');
  document.getElementById(type==='hn'?'statHN':'statLibre').classList.add('active-stat');
  renderDD(type);
}
function closeDD(type){
  openDD=null;
  document.getElementById(type==='hn'?'ddHN':'ddLibre').classList.remove('open');
  document.getElementById(type==='hn'?'statHN':'statLibre').classList.remove('active-stat');
}
function renderDD(type){
  const list=byE[curFloor]||[];const cfg=ECFG[curFloor];const c1=cfg.color.split(',')[0];
  if(type==='hn'){
    document.getElementById('ddHNFloor').textContent=cfg.label;
    const items=list.filter(isHN);const cont=document.getElementById('ddHNList');
    if(!items.length){cont.innerHTML='<div class="stat-dd-empty">Aucun bureau hors norme.</div>';return;}
    cont.innerHTML=items.map(b=>{const diff=parseFloat(b.superficie)-NORMES[b.l_fonct];const nm=[b.nom,b.prenom].filter(Boolean).join(' ')||'—';
      return`<div class="stat-dd-item" onclick="jumpAndClose(${b.id})"><span class="stat-dd-ref" style="color:${c1}">${esc(b.ref_bureau||'—')}</span><span class="stat-dd-name">${esc(nm)}</span><span class="stat-dd-val" style="color:#DC2626">+${fmt(diff,1)} m²</span></div>`;}).join('');
  }else{
    document.getElementById('ddLibreFloor').textContent=cfg.label;
    const items=list.filter(isLibre);const cont=document.getElementById('ddLibreList');
    if(!items.length){cont.innerHTML='<div class="stat-dd-empty">Aucun bureau libre.</div>';return;}
    cont.innerHTML=items.map(b=>`<div class="stat-dd-item" onclick="jumpAndClose(${b.id})"><span class="stat-dd-ref" style="color:#0369A1">${esc(b.ref_bureau||'—')}</span><span class="stat-dd-name">${esc(b.direction_centrale||b.direction||'Bureau libre')}</span>${b.superficie?`<span class="stat-dd-val" style="color:#0369A1">${fmt(b.superficie,1)} m²</span>`:''}</div>`).join('');
  }
}
function jumpAndClose(id){if(openDD)closeDD(openDD);selBureau(String(id));setTimeout(()=>{const c=document.querySelector(`.bcard[data-id="${String(id)}"]`);if(c)c.scrollIntoView({behavior:'smooth',block:'nearest'});},60);}

/* ────────── Floor switch ────────── */
function switchFloor(n,push){
  curFloor=n;
  activeId=null;
  if(openDD)closeDD(openDD);
  document.getElementById('listSearch').value='';
  document.querySelectorAll('.fi').forEach(f=>f.classList.remove('active'));
  document.getElementById('fi'+n).classList.add('active');
  /* Toujours vider le panneau détail sans exception */
  document.getElementById('emptyDet').style.display='flex';
  document.getElementById('detCont').style.display='none';
  document.getElementById('planEtagePanel').style.display='none';
  document.querySelectorAll('.bcard').forEach(c=>c.classList.remove('sel'));
  document.querySelectorAll('[data-bureau-id]').forEach(el=>{el.setAttribute('stroke','none');el.style.filter='';});
  const cfg=ECFG[n];const list=byE[n]||[];
  const occ=list.filter(b=>b.mle&&b.mle.trim()).length;
  const libre=list.filter(isLibre).length;
  const hn=list.filter(isHN).length;
  document.getElementById('navLabel').textContent=cfg.label;
  document.getElementById('c2Name').textContent=cfg.label;
  document.getElementById('c2Sub').textContent=cfg.sub;
  document.getElementById('addEtage').value=n;
  document.getElementById('addFloorLbl').textContent=cfg.label;
  const c1=cfg.color.split(',')[0],c2=cfg.color.split(',')[1];
  document.documentElement.style.setProperty('--ac',c1);
  document.documentElement.style.setProperty('--ac2',c2);
  document.getElementById('c2Stats').innerHTML=`
    <div class="c2-stat"><div class="c2-sv">${list.length}</div><div class="c2-sl">Total</div></div>
    <div class="c2-stat"><div class="c2-sv" style="color:#15803D">${occ}</div><div class="c2-sl">Occ.</div></div>
    <div class="c2-stat clickable" id="statLibre" onclick="toggleDD('libre')">
      <div class="c2-sv" style="color:#0369A1">${libre}</div>
      <div class="c2-sl" style="color:#0369A1">Libres ▾</div>
    </div>`;
  document.getElementById('hnCount').textContent=hn;
  const dirs=[...new Set(list.map(b=>b.direction_centrale||b.direction).filter(Boolean))].sort();
  const ds=document.getElementById('dirFilter');
  ds.innerHTML='<option value="">— Toutes les directions —</option>'+dirs.map(d=>`<option value="${esc(d.toLowerCase())}">${esc(d)}</option>`).join('');
  if(push)history.replaceState(null,'','?etage='+n);
  renderCards(list,'','');
}

function renderCards(list,q,dir){
  const sq=(q||'').toLowerCase().trim(),sd=(dir||'').toLowerCase().trim();
  const f=sq||sd?list.filter(b=>(!sq||(b.mle||'').toLowerCase().includes(sq)||(b.nom||'').toLowerCase().includes(sq)||(b.prenom||'').toLowerCase().includes(sq)||(b.ref_bureau||'').toLowerCase().includes(sq)||(b.direction||'').toLowerCase().includes(sq)||(b.direction_centrale||'').toLowerCase().includes(sq))&&(!sd||(b.direction_centrale||b.direction||'').toLowerCase().includes(sd))):list;
  const cont=document.getElementById('c2List');
  if(!f.length){cont.innerHTML=`<div class="no-res">${sq||sd?'Aucun résultat.':'Aucun bureau.'}</div>`;return;}
  cont.innerHTML='';
  f.forEach(b=>{
    const occ=b.mle&&b.mle.trim();const st=b.statut||(occ?'Occupé':'Libre');const bc=bclass(st);
    const hn=isHN(b);
    const conflicts=getConflicts(b);
    const hasConflict=conflicts.length>0;
    const nm=occ?[b.nom,b.prenom].filter(Boolean).join(' '):'';
    const cfg=ECFG[parseInt(b.etage)]||{color:'#999,#bbb'};const c=cfg.color.split(',')[0];
    const card=document.createElement('div');
    card.className='bcard'+(hn?' hn-c':'')+(hasConflict?' conflict-c':'')+(b.id==activeId?' sel':'');
    card.dataset.id=b.id;
    card.innerHTML=`<div class="bc-top">
      <span class="bc-ref" style="background:${c}18;color:${c}">${hl(b.ref_bureau||'—',sq)}</span>
      ${hn?'<span class="bc-hn">⚠ Norme</span>':''}
      ${hasConflict?`<span class="bc-conflict">⚡ Conflit ×${conflicts.length}</span>`:''}
      ${b.decision_pdf?'<span style="font-size:9px;background:#DCFCE7;color:#15803D;padding:1px 5px;border-radius:4px;font-weight:700;">📄</span>':''}
      ${b.cle_detachee==1&&b.cle_detachee_par==='Mme. Bahri'?'<span style="font-size:9px;background:#DCFCE7;color:#15803D;padding:1px 5px;border-radius:4px;font-weight:700;" title="Clé récupérée par Mme. Bahri">🔑✅</span>':b.cle_prise_par?'<span style="font-size:9px;background:#DBEAFE;color:#1D4ED8;padding:1px 5px;border-radius:4px;font-weight:700;" title="Clé prise par l\'employé">🗝️</span>':''}
      <span class="bc-st ${bc}">${esc(st)}</span>
    </div>
    <div class="bc-name">${occ?hl(nm,sq):'<span style="color:var(--muted);font-weight:400">Bureau libre</span>'}</div>
    <div class="bc-meta">
      ${occ?`<span class="bc-mle">${hl(b.mle,sq)}</span>`:''}
      <span class="bc-dir-t">${hl(b.direction_centrale||b.direction||'',sq)}</span>
      ${b.superficie?`<span class="bc-m2">${fmt(b.superficie,1)} m²</span>`:''}
    </div>`;
    card.onclick=()=>selBureau(String(b.id));
    cont.appendChild(card);
  });
  if(sq.length>=3&&f.length===1)selBureau(String(f[0].id));
}
function filterList(q,dir){renderCards(byE[curFloor]||[],q,dir);}
function globalSearch(q){
  const sq=(q||'').toLowerCase().trim();
  if(!sq){switchFloor(curFloor,false);return;}
  const res=ALL.filter(b=>(b.mle||'').toLowerCase().includes(sq)||(b.nom||'').toLowerCase().includes(sq)||(b.prenom||'').toLowerCase().includes(sq)||(b.ref_bureau||'').toLowerCase().includes(sq)||(b.direction||'').toLowerCase().includes(sq)||(b.direction_centrale||'').toLowerCase().includes(sq));
  document.getElementById('c2Name').textContent=`Résultats (${res.length})`;
  document.getElementById('c2Sub').textContent='Recherche — tous étages';
  document.querySelectorAll('.fi').forEach(f=>f.classList.remove('active'));
  renderCards(res,sq,'');clearDet();
}

function selBureau(id){
  const b=ALL.find(x=>String(x.id)===String(id));if(!b)return;
  /* Si le bureau est sur un autre étage, changer d'étage automatiquement */
  const bEtage=parseInt(b.etage);
  if(bEtage!==curFloor){
    curFloor=bEtage;
    document.querySelectorAll('.fi').forEach(f=>f.classList.remove('active'));
    const fiEl=document.getElementById('fi'+bEtage);if(fiEl)fiEl.classList.add('active');
  }
  activeId=id;
  document.querySelectorAll('.bcard').forEach(c=>c.classList.remove('sel'));
  const c=document.querySelector(`.bcard[data-id="${String(id)}"]`);if(c)c.classList.add('sel');
  /* ── Cadre de sélection sur la carte ── */
  document.querySelectorAll('[data-bureau-id]').forEach(el=>{
    el.setAttribute('stroke',       'none');
    el.setAttribute('stroke-width', '0');
  });
  const mapEl=document.querySelector(`[data-bureau-id="${id}"]`);
  if(mapEl){
    mapEl.setAttribute('stroke', 'none');
    mapEl.setAttribute('stroke-width','0');
    /* Ombre portée via filtre SVG */
    mapEl.style.filter='drop-shadow(0 0 5px rgba(255,255,255,0.9)) drop-shadow(0 0 3px rgba(0,0,0,0.7))';
    /* Remettre les autres à zéro */
    document.querySelectorAll('[data-bureau-id]').forEach(el=>{
      if(el!==mapEl) el.style.filter='';
    });
  }
  document.getElementById('emptyDet').style.display='none';
  document.getElementById('planEtagePanel').style.display='none';
  document.getElementById('detCont').style.display='block';
  document.getElementById('detCont').innerHTML=buildDet(b);
}
function clearDet(){
  activeId=null;
  document.getElementById('emptyDet').style.display='flex';
  document.getElementById('detCont').style.display='none';
  document.getElementById('planEtagePanel').style.display='none';
  document.querySelectorAll('[data-bureau-id]').forEach(el=>{
    el.setAttribute('stroke', 'none');
    el.setAttribute('stroke-width','0');
    el.style.filter='';
  });
}

/* ────────── Bureau detail ────────── */
function buildDet(b){
  const occ=b.mle&&b.mle.trim();const st=b.statut||(occ?'Occupé':'Libre');const bc=bclass(st);
  const ini=occ?((b.nom||'?').charAt(0)+(b.prenom||'').charAt(0)).toUpperCase():'—';
  const cfg=ECFG[parseInt(b.etage)]||{color:'#0F2563,#1D4ED8'};
  const c1=cfg.color.split(',')[0],c2=cfg.color.split(',')[1];
  const hn=isHN(b);

  /* ══ DÉTECTION DE CONFLIT ══ */
  const conflicts=getConflicts(b);
  const CONF_COLORS=['#C8102E','#0F2563','#BA7517','#0F6E56','#533AB7','#701A75'];
  let conflictH='';
  if(conflicts.length>0){
    const agentsH=conflicts.map((cx,i)=>{
      const ini2=((cx.nom||'?').charAt(0)+(cx.prenom||'').charAt(0)).toUpperCase();
      const nm=[cx.nom,cx.prenom].filter(Boolean).join(' ')||'—';
      const col=CONF_COLORS[i%CONF_COLORS.length];
      const etLbl=ECFG[parseInt(cx.etage)]?.label||'';
      return `<div class="conflit-agent">
        <div class="conf-av" style="background:${col}">${ini2}</div>
        <div class="conf-info">
          <div class="conf-name">${esc(nm)}</div>
          <div class="conf-meta">${esc(cx.emploi||cx.l_fonct||'')}${cx.mle?' · <span style="font-family:monospace;font-weight:700">'+esc(cx.mle)+'</span>':''} · ${esc(cx.direction_centrale||cx.direction||etLbl)}</div>
        </div>
        <span class="conf-tag">Conflit</span>
      </div>`;
    }).join('');
    conflictH=`<div class="conflit-banner">
      <svg width="17" height="17" viewBox="0 0 16 16" fill="none" style="flex-shrink:0;margin-top:1px">
        <circle cx="8" cy="8" r="7" stroke="#C8102E" stroke-width="1.4"/>
        <path d="M8 5v3M8 10.5v.5" stroke="#C8102E" stroke-width="1.5" stroke-linecap="round"/>
      </svg>
      <div class="conflit-body">
        <div class="conflit-title">
          Ce bureau est aussi affecté à ${conflicts.length} autre${conflicts.length>1?'s':''} agent${conflicts.length>1?'s':''}
        </div>
        <div class="conflit-agents">${agentsH}</div>
        <div class="conflit-footer">
          Utilisez le bouton <strong>Transférer</strong> pour résoudre ce conflit d'affectation.
        </div>
      </div>
    </div>`;
  }
  /* ══ FIN CONFLIT ══ */

  let surfH='';
  if(b.superficie){
    const norme=b.l_fonct&&NORMES[b.l_fonct]?NORMES[b.l_fonct]:null;
    const reel=parseFloat(b.superficie),droit=b.superficie_droit?parseFloat(b.superficie_droit):null;
    const ref=droit||norme;let barH='',alertH='';
    if(ref){const pct=Math.min(100,Math.round(reel/ref*100));const diff=reel-ref;const cls=Math.abs(diff)<0.5?'ok':diff>0?'over':'under';const dc=Math.abs(diff)<0.5?'neu':diff>0?'neg':'pos';
      barH=`<div class="surf-bar"><div class="sb-track"><div class="sb-fill ${cls}" style="width:${pct}%"></div></div><div class="sb-lbls"><span>${fmt(reel,2)} m²</span><span>Réf: ${fmt(ref,2)} m²</span></div><div class="sb-diff ${dc}">${diff>0?'+':''}${fmt(diff,2)} m² (${pct}%)</div></div>`;}
    if(norme){const d=reel-norme;
      if(Math.abs(d)<=2)alertH=`<div class="na ok"><svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M5 10l4 4 6-8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>Conforme à la norme (${norme} m² pour ${esc(b.l_fonct)})</div>`;
      else if(d>2)alertH=`<div class="na over"><svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M10 4l6.5 12H3.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 9v4M10 15h.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>Dépassement +${fmt(d,2)} m² — norme: ${norme} m² (${esc(b.l_fonct)})</div>`;
      else alertH=`<div class="na warn">Surface inférieure de ${fmt(Math.abs(d),2)} m² à la norme (${norme} m²)</div>`;}
    surfH=`<div class="f full ${hn?'':'hi'}"><div class="fl">Surface réelle vs référence</div><div class="fv">${fmt(reel,2)} m² <span style="font-size:11px;color:var(--muted);font-weight:400">(droit: ${droit?fmt(droit,2)+' m²':'N/A'})</span></div>${barH}${alertH}</div>`;}
  const f=(l,v,hi=false,full=false)=>v?`<div class="f${hi?' hi':''}${full?' full':''}"><div class="fl">${l}</div><div class="fv">${esc(v)}</div></div>`:'';

  let decH='';
  if(b.decision_pdf){
    const fname=b.decision_pdf.split('/').pop();
    const isPdf=b.decision_pdf.toLowerCase().endsWith('.pdf');
    decH=`<div class="sec sec-decision">
      <p class="sec-t">📄 Décision de Transfert</p>
      <div class="dec-card">
        <div class="dec-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" stroke="#15803D" stroke-width="1.5"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" stroke="#15803D" stroke-width="1.5" stroke-linecap="round"/></svg></div>
        <div class="dec-info"><div class="dec-name">${esc(fname)}</div><div class="dec-sub">Décision officielle de transfert de bureau</div></div>
        <div class="dec-actions">
          <a href="${esc(b.decision_pdf)}" target="_blank" class="btn btn-g btn-sm">↗ Ouvrir</a>
          <a href="${esc(b.decision_pdf)}" download class="btn btn-g btn-sm">↓ Télécharger</a>
        </div>
      </div>
      ${isPdf?`<div class="pdf-prev-wrap" style="margin-top:4px;"><iframe src="${esc(b.decision_pdf)}" style="width:100%;height:300px;border:none;border-radius:6px;"></iframe></div>`
      :`<div style="margin-top:8px;border-radius:10px;overflow:hidden;"><img src="${esc(b.decision_pdf)}" style="width:100%;cursor:zoom-in;" onclick="openLB('${esc(b.decision_pdf)}')"></div>`}
      <div style="margin-top:10px;">
        <label style="display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:1.5px solid var(--rule);background:var(--white);color:var(--muted);transition:all .15s;" onmouseover="this.style.borderColor='var(--ac)'" onmouseout="this.style.borderColor='var(--rule)'">
          <svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M8 12V4M5 7l3-3 3 3M3 13h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
          Remplacer
          <input type="file" accept=".pdf,.jpg,.jpeg,.png" style="display:none" onchange="uploadDecisionFile(${b.id},${b.etage},this)">
        </label>
      </div>
    </div>`;
  }else{
    decH=`<div class="sec">
      <p class="sec-t">📄 Décision de Transfert</p>
      <p style="font-size:12px;color:var(--muted);margin-bottom:10px;">Aucune décision enregistrée pour ce bureau.</p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button class="btn btn-transfer btn-sm" onclick="openTransfer(${b.id})">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none"><path d="M5 12h14M13 6l6 6-6 6" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Créer une décision de transfert
        </button>
        <label style="display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:8px;font-size:11px;font-weight:600;cursor:pointer;border:1.5px solid var(--rule);background:var(--white);color:var(--muted);transition:all .15s;" onmouseover="this.style.borderColor='var(--ac)'" onmouseout="this.style.borderColor='var(--rule)'">
          <svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M8 12V4M5 7l3-3 3 3M3 13h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
          Importer PDF existant
          <input type="file" accept=".pdf,.jpg,.jpeg,.png" style="display:none" onchange="uploadDecisionFile(${b.id},${b.etage},this)">
        </label>
      </div>
    </div>`;
  }

  return`<div class="dw">
  <div class="dh">
    <div>
      <div class="dh-bc"><span>Siège Social</span><span style="opacity:.4">›</span><span>${esc(cfg.label)}</span><span style="opacity:.4">›</span><span style="color:${c1}">${esc(b.ref_bureau||'—')}</span></div>
      <div class="dh-title">Bureau ${esc(b.ref_bureau||'—')}</div>
      <div class="dh-sub">${[b.l_entite,b.direction_centrale].filter(Boolean).map(esc).join(' · ')}</div>
      ${b.ancien_bureau?`<div style="font-size:11px;margin-top:5px;display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:6px;${occ?'background:#EFF6FF;color:#0369A1;border:1px solid #BFDBFE;':'background:#F0FDF4;color:#15803D;border:1px solid #BBF7D0;'}">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        ${occ ? 'Vient du bureau' : 'Transféré vers'} : <strong style="font-family:monospace">${esc(b.ancien_bureau)}</strong>
      </div>`:''}
      <div class="dh-badges">
        <span class="dh-st ${bc}">${esc(st)}</span>
        ${hn?'<span class="dh-hn">⚠ Hors norme</span>':''}
        ${conflicts.length>0?`<span style="background:#FEE2E2;color:#C8102E;border:1px solid #FECACA;font-size:11px;font-weight:700;padding:4px 11px;border-radius:20px;">⚡ ${conflicts.length} conflit${conflicts.length>1?'s':''}</span>`:''}
        ${b.decision_pdf?'<span style="background:#DCFCE7;color:#15803D;border:1px solid #BBF7D0;font-size:11px;font-weight:700;padding:4px 11px;border-radius:20px;">📄 Décision</span>':''}
      </div>
    </div>
    <div class="dh-actions">
      ${CAN_UPDATE ? `<button class="btn btn-transfer btn-sm" onclick="openTransfer(${b.id})">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none"><path d="M5 12h14M13 6l6 6-6 6" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>Transférer
      </button>
      <button class="btn btn-g btn-sm" onclick="openEdit(${b.id})">
        <svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M11.5 2.5a1.414 1.414 0 0 1 2 2L5 13H3v-2L11.5 2.5z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>Modifier
      </button>` : ''}
      ${CAN_DELETE ? `<button class="btn btn-d btn-sm" onclick="openDel(${b.id},'${esc(b.ref_bureau||'')}',${b.etage})">
        <svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M2 4h12M5 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1M6 7v5M10 7v5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>Supprimer
      </button>` : ''}
    </div>
  </div>

  ${conflictH}

  ${occ?`<div class="sec"><p class="sec-t">Occupant</p>
    <div class="occ-p">
      <div class="occ-av" style="background:linear-gradient(135deg,${c1},${c2})">${ini}</div>
      <div>
        <div class="occ-name">${esc(b.nom||'')} ${esc(b.prenom||'')}</div>
        <div class="occ-emp">${esc(b.emploi||'')}${b.l_fonct?' · <strong>'+esc(b.l_fonct)+'</strong>':''}</div>
        <span class="occ-mle" style="background:${c1}18;color:${c1}">${esc(b.mle)}</span>
      </div>
    </div></div>`:''}
  <div class="sec ${hn?'sec-hn':''}">
    <p class="sec-t">Identification & Surface${hn?' — ⚠ Hors norme':''}</p>
    <div class="fg">
      ${f('Référence',b.ref_bureau,true)}${f('Classification',b.l_classf)}
      ${surfH}${f('Notes',b.notes,false,true)}
    </div>
  </div>
  <div class="sec">
    <p class="sec-t">Rattachement organisationnel</p>
    <div class="fg">
      ${f('Entité / Service',b.l_entite,true)}${f('Direction',b.direction)}
      ${f('Direction Centrale',b.direction_centrale,true,true)}
    </div>
  </div>
  ${buildCleSection(b)}
  ${decH}
  </div>`;
}

/* ────────── Clé de bureau ────────── */
function fmtDatetime(str){
  if(!str)return'—';
  const d=new Date(str.replace(' ','T'));
  if(isNaN(d))return str;
  return d.toLocaleDateString('fr-TN',{day:'2-digit',month:'2-digit',year:'numeric'})+' à '+d.toLocaleTimeString('fr-TN',{hour:'2-digit',minute:'2-digit'});
}

function buildCleSection(b){
  // ── Dériver le statut depuis les vraies colonnes DB ──
  // cle_detachee (0/1), cle_detachee_par ('Mme. Bahri' | 'non_remise' | null)
  // cle_prise_par (nom employé | null), cle_prise_date (datetime | null)
  const libre   = isLibre(b);
  const prise   = !!b.cle_prise_par;                       // employé a pris la clé
  const bahri   = b.cle_detachee==1 && b.cle_detachee_par==='Mme. Bahri';
  const nonRem  = b.cle_detachee==0 && b.cle_detachee_par==='non_remise';
  const nonPris = b.cle_detachee==0 && b.cle_detachee_par==='non_prise';
  const nomEmp  = esc([b.nom,b.prenom].filter(Boolean).join(' '))||'l\'employé';
  const bid     = b.id;
  const ref     = esc(b.ref_bureau||'');

  /* ══════════════════════════════════════
     CAS BUREAU LIBRE
  ══════════════════════════════════════ */
  if(libre){

    if(bahri){
      return `<div class="sec cle-section">
        <p class="sec-t">🔑 Clé du bureau</p>
        <div class="cle-card" style="background:#F0FDF4;border-color:#BBF7D0;">
          <div class="cle-icon" style="background:#DCFCE7;font-size:22px;">✅</div>
          <div class="cle-info">
            <div class="cle-title">Clé récupérée par <strong>Ms. Bahri (DPA)</strong></div>
            <div class="cle-sub">Le <strong>${fmtDatetime(b.cle_prise_date)}</strong></div>
          </div>
          <div class="cle-actions">
            <button class="btn btn-cle-reset btn-sm" onclick="cleAction(${bid},'reset','','${ref}')">↺ Réinitialiser</button>
          </div>
        </div>
      </div>`;
    }

    if(nonRem){
      return `<div class="sec cle-section">
        <p class="sec-t">🔑 Clé du bureau</p>
        <div class="cle-card" style="background:#FEF2F2;border-color:#FECACA;">
          <div class="cle-icon" style="background:#FEE2E2;font-size:22px;">❌</div>
          <div class="cle-info">
            <div class="cle-title">Ms. Bahri n'a pas encore récupéré la clé</div>
            <div class="cle-sub">Enregistré le <strong>${fmtDatetime(b.cle_prise_date)}</strong></div>
          </div>
          <div class="cle-actions">
            <button class="btn btn-cle-yes btn-sm" onclick="cleAction(${bid},'remise','','${ref}')">✅ Elle a pris la clé</button>
            <button class="btn btn-cle-reset btn-sm" onclick="cleAction(${bid},'reset','','${ref}')">↺ Réinitialiser</button>
          </div>
        </div>
      </div>`;
    }

    // Aucun statut → poser la question
    return `<div class="sec cle-section">
      <p class="sec-t">🔑 Clé du bureau</p>
      <div class="cle-card" style="background:#FFFBEB;border-color:#FDE68A;">
        <div class="cle-icon" style="background:#FEF3C7;font-size:22px;">🔑</div>
        <div class="cle-info">
          <div class="cle-title">Bureau libre — Ms. Bahri a-t-elle récupéré la clé ?</div>
          <div class="cle-sub">La date et l'heure seront enregistrées automatiquement au clic.</div>
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:10px;">
        <button class="btn btn-cle-yes" style="flex:1;justify-content:center;padding:11px 0;font-size:13px;" onclick="cleAction(${bid},'remise','','${ref}')">
          ✅ Oui — Bahri a pris la clé
        </button>
        <button class="btn btn-d" style="flex:1;justify-content:center;padding:11px 0;font-size:13px;" onclick="cleAction(${bid},'non_remise','','${ref}')">
          ❌ Non — Pas encore récupérée
        </button>
      </div>
    </div>`;
  }

  /* ══════════════════════════════════════
     CAS BUREAU OCCUPÉ
  ══════════════════════════════════════ */

  if(prise){
    return `<div class="sec cle-section">
      <p class="sec-t">🔑 Clé du bureau</p>
      <div class="cle-card" style="background:#EFF6FF;border-color:#BFDBFE;">
        <div class="cle-icon" style="background:#DBEAFE;font-size:22px;">🗝️</div>
        <div class="cle-info">
          <div class="cle-title">Clé remise à <strong>${esc(b.cle_prise_par)}</strong></div>
          <div class="cle-sub">Le <strong>${fmtDatetime(b.cle_prise_date)}</strong></div>
        </div>
        <div class="cle-actions">
          <button class="btn btn-cle-reset btn-sm" onclick="cleAction(${bid},'reset','','${ref}')">↺ Réinitialiser</button>
        </div>
      </div>
    </div>`;
  }

  if(nonPris){
    return `<div class="sec cle-section">
      <p class="sec-t">🔑 Clé du bureau</p>
      <div class="cle-card" style="background:#FEF2F2;border-color:#FECACA;">
        <div class="cle-icon" style="background:#FEE2E2;font-size:22px;">❌</div>
        <div class="cle-info">
          <div class="cle-title">${nomEmp} n'a pas encore pris la clé</div>
          <div class="cle-sub">Enregistré le <strong>${fmtDatetime(b.cle_prise_date)}</strong></div>
        </div>
        <div class="cle-actions">
          <button class="btn btn-cle-prise btn-sm" onclick="showClePrise(${bid},'${ref}')">✅ Il/Elle a pris la clé</button>
          <button class="btn btn-cle-reset btn-sm" onclick="cleAction(${bid},'reset','','${ref}')">↺ Réinitialiser</button>
        </div>
      </div>
      <div class="cle-prise-box" id="clePriseBox_${bid}">
        <div class="cle-confirm-q">🔑 Confirmer la prise de clé par :</div>
        <input type="text" id="clePreneurInp_${bid}" class="fg-inp" style="margin-bottom:10px;width:100%;" placeholder="Nom de l'employé" value="${nomEmp}">
        <div style="display:flex;gap:8px;">
          <button class="btn btn-cle-prise btn-sm" onclick="cleAction(${bid},'prise',document.getElementById('clePreneurInp_${bid}').value,'${ref}')">✅ Confirmer</button>
          <button class="btn btn-cle-reset btn-sm" onclick="document.getElementById('clePriseBox_${bid}').classList.remove('show')">Annuler</button>
        </div>
      </div>
    </div>`;
  }

  // Aucun statut → poser la question pour l'employé
  return `<div class="sec cle-section">
    <p class="sec-t">🔑 Clé du bureau</p>
    <div class="cle-card" style="background:#FFFBEB;border-color:#FDE68A;">
      <div class="cle-icon" style="background:#FEF3C7;font-size:22px;">🔑</div>
      <div class="cle-info">
        <div class="cle-title">${nomEmp} a-t-il/elle pris la clé de ce bureau ?</div>
        <div class="cle-sub">La date et l'heure seront enregistrées automatiquement au clic.</div>
      </div>
    </div>
    <div style="display:flex;gap:10px;margin-top:10px;">
      <button class="btn btn-cle-prise" style="flex:1;justify-content:center;padding:11px 0;font-size:13px;" onclick="showClePrise(${bid},'${ref}')">
        ✅ Oui — Il/Elle a pris la clé
      </button>
      <button class="btn btn-d" style="flex:1;justify-content:center;padding:11px 0;font-size:13px;" onclick="cleAction(${bid},'non_prise','','${ref}')">
        ❌ Non — Pas encore prise
      </button>
    </div>
    <div class="cle-prise-box" id="clePriseBox_${bid}">
      <div class="cle-confirm-q">🔑 Confirmer la prise de clé par :</div>
      <input type="text" id="clePreneurInp_${bid}" class="fg-inp" style="margin-bottom:10px;width:100%;" placeholder="Nom de l'employé" value="${nomEmp}">
      <div style="display:flex;gap:8px;">
        <button class="btn btn-cle-prise btn-sm" onclick="cleAction(${bid},'prise',document.getElementById('clePreneurInp_${bid}').value,'${ref}')">✅ Confirmer</button>
        <button class="btn btn-cle-reset btn-sm" onclick="document.getElementById('clePriseBox_${bid}').classList.remove('show')">Annuler</button>
      </div>
    </div>
  </div>`;
}
function showClePrise(id, ref){
  const box=document.getElementById('clePriseBox_'+id);
  if(box) box.classList.toggle('show');
}

function cleAction(id, type, preneur, ref){
  const fd=new FormData();
  fd.append('action','cle_action');
  fd.append('id', id);
  fd.append('cle_type', type);
  fd.append('cle_preneur', preneur||'');
  fetch('', {method:'POST', body:fd})
    .then(r=>r.json())
    .then(data=>{
      if(!data.ok) return;
      const b=ALL.find(x=>String(x.id)===String(id));
      if(b){
        if(type==='remise')          { b.cle_detachee=1; b.cle_detachee_par='Mme. Bahri'; b.cle_prise_date=data.now; b.cle_prise_par=null; }
        else if(type==='non_remise') { b.cle_detachee=0; b.cle_detachee_par='non_remise'; b.cle_prise_date=data.now; b.cle_prise_par=null; }
        else if(type==='prise')      { b.cle_detachee=1; b.cle_prise_par=preneur; b.cle_prise_date=data.now; b.cle_detachee_par=null; }
        else if(type==='non_prise')  { b.cle_detachee=0; b.cle_detachee_par='non_prise'; b.cle_prise_date=data.now; b.cle_prise_par=null; }
        else if(type==='reset')      { b.cle_detachee=0; b.cle_detachee_par=null; b.cle_prise_date=null; b.cle_prise_par=null; }
      }
      selBureau(String(id));
    })
    .catch(()=>location.reload());
}

/* ────────── Upload décision directe ────────── */
function uploadDecisionFile(id,etage,input){
  if(!input.files[0])return;
  const fd=new FormData();
  fd.append('action','upload_decision');fd.append('id',id);fd.append('etage',etage);fd.append('decision_file',input.files[0]);
  fetch('',{method:'POST',body:fd}).then(()=>location.reload());
}

/* ────────── Transfer modal ────────── */
let trBureau=null,trGeneratedPDFDoc=null,trUploadedFile=null;

function openTransfer(id){
  const b=ALL.find(x=>x.id==id);if(!b)return;
  trBureau=b; trGeneratedPDFDoc=null; trUploadedFile=null;
  document.getElementById('trOldRef').textContent=b.ref_bureau||'—';
  document.getElementById('tr_old_ref').value=b.ref_bureau||'';
  document.getElementById('tr_new_ref').value='';
  document.getElementById('tr_mle').value=b.mle||'';
  document.getElementById('tr_prenom').value=b.prenom||'';
  document.getElementById('tr_nom').value=b.nom||'';
  document.getElementById('tr_direction').value=b.direction_centrale||b.direction||'';
  const today=new Date();
  document.getElementById('tr_date').value=today.toISOString().split('T')[0];
  document.getElementById('tr_sg_num').value='';
  document.getElementById('tr_signataire').value='HAMZA LOUATI';
  document.getElementById('pdfPrevWrap').innerHTML='<div style="padding:40px;color:var(--muted);font-size:13px;">Cliquez sur "Générer" pour visualiser la décision.</div>';
  document.getElementById('trFilePreview').style.display='none';
  document.getElementById('trDropLabel').textContent='Cliquer ou glisser un fichier PDF / image ici';
  document.getElementById('trUploadFile').value='';
  const libres=ALL.filter(x=>{
    const st=(x.statut||'').toLowerCase();
    if(st==='libre') return true;
    if(st==='occupé'||st==='occupe'||st==='en travaux'||st==='dépôt'||st==='depot') return false;
    return !x.mle||!String(x.mle).trim();
  });
  const dl=document.getElementById('libresDatalist');
  dl.innerHTML=libres.map(x=>{
    const etLbl=ECFG[parseInt(x.etage)]?.short||x.etage;
    const dir=x.direction_centrale||x.direction||'';
    const m2=x.superficie?` · ${parseFloat(x.superficie).toFixed(1)} m²`:'';
    return `<option value="${(x.ref_bureau||'').replace(/"/g,'&quot;')}">${x.ref_bureau} — Ét. ${etLbl}${dir?' · '+dir:''}${m2}</option>`;
  }).join('');
  const hint=document.getElementById('trLibresHint');
  if(libres.length){
    hint.style.display='flex';hint.style.background='#EFF6FF';hint.style.color='#0369A1';hint.style.borderColor='#BFDBFE';
    hint.innerHTML=`<svg width="11" height="11" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5"/><path d="M10 9v5M10 7h.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>${libres.length} bureau(x) libre(s) disponible(s)`;
  }else{
    hint.style.display='flex';hint.style.background='#FEF2F2';hint.style.color='#DC2626';hint.style.borderColor='#FECACA';
    hint.textContent='Aucun bureau libre disponible actuellement';
  }
  document.getElementById('tr_old_ref_lbl').textContent=b.ref_bureau||'';
  document.getElementById('tr_new_ref_lbl2').textContent='';
  setStatutAncien('Libre');
  setStatutNouveau('Occupé');
  document.getElementById('trNewBureauInfo').style.display='none';
  switchTrTab('infos');
  openModal('transferModal');
}

function switchTrTab(tab){
  document.querySelectorAll('.tr-tab').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.tr-panel').forEach(p=>p.classList.remove('active'));
  const tabMap={infos:0,gen:1,upload:2};
  document.querySelectorAll('.tr-tab')[tabMap[tab]].classList.add('active');
  document.getElementById({infos:'trPanelInfos',gen:'trPanelGen',upload:'trPanelUpload'}[tab]).classList.add('active');
}

function setStatutAncien(val){
  document.getElementById('inp_statut_ancien').value=val;
  const btnL=document.getElementById('btnAncienLibre'),btnC=document.getElementById('btnAncienConserver'),lbl=document.getElementById('statut_ancien_lbl');
  if(val==='Libre'){btnL.style.fontWeight='800';btnL.style.boxShadow='0 0 0 2px #15803D';btnC.style.boxShadow='none';btnC.style.fontWeight='600';lbl.textContent='→ L\'ancien bureau sera marqué Libre';lbl.style.color='#15803D';}
  else{btnC.style.fontWeight='800';btnC.style.boxShadow='0 0 0 2px #6B7280';btnL.style.boxShadow='none';btnL.style.fontWeight='700';lbl.textContent='→ Statut de l\'ancien bureau inchangé';lbl.style.color='#6B7280';}
}
function setStatutNouveau(val){
  document.getElementById('inp_statut_nouveau').value=val;
  const btnO=document.getElementById('btnNouveauOccupe'),btnC=document.getElementById('btnNouveauConserver'),lbl=document.getElementById('statut_nouveau_lbl');
  if(val==='Occupé'){btnO.style.fontWeight='800';btnO.style.boxShadow='0 0 0 2px #1D4ED8';btnC.style.boxShadow='none';btnC.style.fontWeight='600';lbl.textContent='→ Le nouveau bureau sera marqué Occupé';lbl.style.color='#1D4ED8';}
  else{btnC.style.fontWeight='800';btnC.style.boxShadow='0 0 0 2px #6B7280';btnO.style.boxShadow='none';btnO.style.fontWeight='700';lbl.textContent='→ Statut du nouveau bureau inchangé';lbl.style.color='#6B7280';}
}

function trCheckNewBureau(val){
  const ref=(val||'').trim();
  document.getElementById('tr_new_ref_lbl2').textContent=ref;
  const info=document.getElementById('trNewBureauInfo');
  if(!ref){info.style.display='none';return;}
  const found=ALL.find(x=>(x.ref_bureau||'').toLowerCase()===ref.toLowerCase());
  if(found){
    const libre=isLibre(found);const st=found.statut||(libre?'Libre':'Occupé');const etLbl=ECFG[parseInt(found.etage)]?.label||'';const nm=[found.nom,found.prenom].filter(Boolean).join(' ')||'—';
    info.style.display='block';info.style.borderColor=libre?'#BBF7D0':'#FECACA';info.style.background=libre?'#F0FDF4':'#FEF2F2';
    info.innerHTML=`<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;"><span style="font-weight:700;color:${libre?'#15803D':'#DC2626'};">${libre?'🟢 Libre':'🔴 Occupé'}</span><span style="color:var(--muted);">${esc(etLbl)}</span>${!libre?`<span style="font-family:monospace;font-size:10px;color:var(--navy);font-weight:700;">${esc(found.mle||'')}</span><span>${esc(nm)}</span>`:''} ${found.superficie?`<span style="margin-left:auto;font-family:monospace;font-size:10px;">${fmt(found.superficie,1)} m²</span>`:''}</div>`;
  }else{
    info.style.display='block';info.style.borderColor='#FDE68A';info.style.background='#FFFBEB';
    info.innerHTML=`<span style="color:#D97706;font-weight:600;">⚠ Bureau non trouvé dans la base</span>`;
  }
}
function trUpdatePreview(){}

function loadLogoBase64(){
  return new Promise(res=>{
    const img=new Image();img.crossOrigin='anonymous';
    img.onload=()=>{try{const c=document.createElement('canvas');c.width=img.naturalWidth||img.width;c.height=img.naturalHeight||img.height;c.getContext('2d').drawImage(img,0,0);res(c.toDataURL('image/png'));}catch(e){res(null);}};
    img.onerror=()=>res(null);img.src='logo.webp?_t='+Date.now();
  });
}

async function buildPDF(){
  const {jsPDF}=window.jspdf;
  const doc=new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});
  const pgW=210,mgL=20,mgR=20,cW=pgW-mgL-mgR;
  const logoB64=await loadLogoBase64();
  doc.setFillColor(200,16,46);doc.rect(0,0,pgW,2,'F');
  if(logoB64){doc.addImage(logoB64,'PNG',mgL,4,22,22);}
  const txtX=logoB64?mgL+25:mgL;
  doc.setFont('helvetica','bold');doc.setFontSize(14);doc.setTextColor(200,16,46);doc.text('TUNISAIR',txtX,14);
  doc.setFontSize(9);doc.setFont('helvetica','normal');doc.setTextColor(120,120,120);doc.text('Compagnie Nationale Tunisienne',txtX,21);
  const rawDate=document.getElementById('tr_date').value;
  const months=['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
  let dateStr='';if(rawDate){const d=new Date(rawDate);dateStr=`${String(d.getDate()).padStart(2,'0')} ${months[d.getMonth()].toUpperCase()} ${d.getFullYear()}`;}
  const sgNum=document.getElementById('tr_sg_num').value||'—';
  doc.setFont('helvetica','normal');doc.setFontSize(10);doc.setTextColor(0);
  doc.text(`Tunis, le ${dateStr}`,pgW-mgR,14,{align:'right'});doc.text(`SG N° ${sgNum}`,pgW-mgR,20,{align:'right'});
  doc.setDrawColor(200,16,46);doc.setLineWidth(0.6);doc.line(mgL,29,pgW-mgR,29);doc.setLineWidth(0.2);doc.setDrawColor(0);
  doc.setFont('helvetica','bold');doc.setFontSize(13);doc.setTextColor(0);
  doc.text('DÉCISION DE TRANSFERT / AFFECTATION DE BUREAUX',pgW/2,40,{align:'center'});
  doc.setFont('helvetica','normal');doc.setFontSize(10);
  const bodyTxt=`\tEn application des dispositions de la note de service DG n° 546 du 05 juin 2023, Il a été décidé de procéder au transfert du personnel comme suit :`;
  doc.text(doc.splitTextToSize(bodyTxt,cW),mgL,52);
  const ancienBureau=document.getElementById('tr_old_ref').value||'—';
  const nouveauBureau=document.getElementById('tr_new_ref').value.trim()||'—';
  const b=trBureau||{};
  doc.autoTable({
    startY:65,head:[['MLE','PRÉNOM','NOM','DIRECTION','ANC. BUREAU','NV. BUREAU']],
    body:[[b.mle||'',b.prenom||'',b.nom||'',(b.direction_centrale||b.direction||'').substring(0,18),ancienBureau,nouveauBureau]],
    theme:'plain',
    styles:{font:'helvetica',fontSize:10,halign:'center',valign:'middle',cellPadding:6,lineWidth:0.3,lineColor:[0,0,0],textColor:[0,0,0]},
    headStyles:{fontStyle:'bold',fillColor:[245,245,245],textColor:[0,0,0],lineWidth:0.3,lineColor:[0,0,0],cellPadding:5},
    bodyStyles:{fillColor:[255,255,255]},
    columnStyles:{0:{cellWidth:20},1:{cellWidth:28},2:{cellWidth:32},3:{cellWidth:40},4:{cellWidth:25},5:{cellWidth:25}},
    margin:{left:mgL,right:mgR},tableLineWidth:0.3,tableLineColor:[0,0,0],
  });
  const endY=doc.lastAutoTable.finalY+14;
  doc.setFont('helvetica','normal');doc.setFontSize(10);
  doc.text('- Les clefs des anciens bureaux doivent être remises à la Direction du',mgL,endY);
  doc.text('Patrimoine et Archives (DPA).',mgL,endY+7);
  const sigY=endY+30;
  doc.setFont('helvetica','bold');doc.setFontSize(11);
  doc.text('LE CHARGÉ DU SECRÉTARIAT GÉNÉRAL',pgW/2,sigY,{align:'center'});
  doc.text(document.getElementById('tr_signataire').value||'HAMZA LOUATI',pgW/2,sigY+10,{align:'center'});
  doc.setFillColor(200,16,46);doc.rect(0,295,pgW,2,'F');
  trGeneratedPDFDoc=doc;
  const blob=doc.output('blob');const url=URL.createObjectURL(blob);
  document.getElementById('pdfPrevWrap').innerHTML=`<iframe src="${url}" style="width:100%;height:360px;border:none;border-radius:6px;"></iframe>`;
}

function downloadPreviewPDF(){
  if(!trGeneratedPDFDoc)return;
  const ref=document.getElementById('tr_new_ref').value||'transfert';
  trGeneratedPDFDoc.save(`Decision_Transfert_${ref}.pdf`);
}

function applyTransferLocally(oldRef, newRef, statutAncien, statutNouveau){
  /* Trouver les deux bureaux dans ALL */
  const ancien = ALL.find(b=>(b.ref_bureau||'').toLowerCase()===oldRef.toLowerCase());
  const nouveau = ALL.find(b=>(b.ref_bureau||'').toLowerCase()===newRef.toLowerCase());
  if(!ancien){alert('Bureau source introuvable dans les données.');return;}
  if(!nouveau){alert('Bureau cible introuvable dans les données.');return;}

  /* Champs à transférer */
  const champs=['mle','nom','prenom','emploi','l_fonct','l_entite','direction','direction_centrale','l_classf'];

  /* Copier les données de l'employé dans le nouveau bureau */
  champs.forEach(c=>{ nouveau[c]=ancien[c]||null; });
  nouveau.statut = statutNouveau||'Occupé';
  nouveau.ancien_bureau = oldRef;

  /* Vider l'ancien bureau */
  champs.forEach(c=>{ ancien[c]=null; });
  ancien.statut = statutAncien||'Libre';
  ancien.decision_pdf = null;

  /* Reconstruire byE */
  Object.keys(byE).forEach(k=>{ byE[k]=[]; });
  ALL.forEach(b=>{ const e=parseInt(b.etage); if(!byE[e])byE[e]=[]; byE[e].push(b); });

  /* Rafraîchir la liste et le map de l'étage courant */
  switchFloor(curFloor, false);

  closeModal('transferModal');
}

function submitTransferDirect(){
  if(!trBureau)return;
  const newRef=document.getElementById('tr_new_ref').value.trim();
  if(!newRef){alert('Veuillez saisir le nouveau bureau.');return;}
  const statutAncien=document.getElementById('inp_statut_ancien').value;
  const statutNouveau=document.getElementById('inp_statut_nouveau').value;

  /* 1. Mise à jour visuelle immédiate */
  applyTransferLocally(trBureau.ref_bureau||'', newRef, statutAncien, statutNouveau);

  /* 2. Sauvegarde en base via formulaire caché (en arrière-plan) */
  document.getElementById('tr_etage_h').value=trBureau.etage;
  document.getElementById('tr_id_h').value=trBureau.id;
  document.getElementById('tr_old_h').value=trBureau.ref_bureau||'';
  document.getElementById('tr_new_h').value=newRef;
  document.getElementById('tr_pdf_b64_h').value='';
  const form=document.getElementById('transferForm');
  form.querySelectorAll('input[name="statut_ancien"],input[name="statut_nouveau"]').forEach(e=>e.remove());
  let inpA=document.createElement('input');inpA.type='hidden';inpA.name='statut_ancien';inpA.value=statutAncien;
  let inpN=document.createElement('input');inpN.type='hidden';inpN.name='statut_nouveau';inpN.value=statutNouveau;
  form.appendChild(inpA);form.appendChild(inpN);

  const fd=new FormData(form);
  fetch('',{method:'POST',body:fd}).then(resp=>{ location.href=resp.url||location.href; }).catch(()=>{});
}

async function submitTransferWithGenPDF(){
  if(!trBureau)return;
  if(!trGeneratedPDFDoc){alert('Veuillez d\'abord générer le PDF.');return;}
  const newRef=document.getElementById('tr_new_ref').value.trim();
  if(!newRef){alert('Veuillez saisir le nouveau bureau.');return;}
  const btn=document.getElementById('btnSubmitGenPDF');
  btn.disabled=true;btn.innerHTML='<span class="spin"></span> Enregistrement…';
  const statutAncien=document.getElementById('inp_statut_ancien').value;
  const statutNouveau=document.getElementById('inp_statut_nouveau').value;

  /* 1. Mise à jour visuelle immédiate */
  applyTransferLocally(trBureau.ref_bureau||'', newRef, statutAncien, statutNouveau);

  /* 2. Sauvegarde en base avec PDF via FormData */
  const pdfBlob=trGeneratedPDFDoc.output('blob');
  const fd=new FormData();
  fd.append('action','transfer');
  fd.append('etage',trBureau.etage);
  fd.append('id',trBureau.id);
  fd.append('old_ref_bureau',trBureau.ref_bureau||'');
  fd.append('new_ref_bureau',newRef);
  fd.append('statut_ancien',statutAncien);
  fd.append('statut_nouveau',statutNouveau);
  fd.append('decision_file',pdfBlob,'decision_transfert.pdf');
  try{
    const resp=await fetch('',{method:'POST',body:fd});
    btn.innerHTML='✓ Transfert enregistré';
    // ── Recharger la page vers le bureau cible pour refléter le decision_pdf
    //    fraîchement enregistré en base (sinon l'UI garde l'ancien état en mémoire) ──
    location.href=resp.url||location.href;
  }catch(e){
    btn.disabled=false;btn.innerHTML='Réessayer';
  }
}

async function submitTransferWithUpload(){
  if(!trBureau)return;
  const newRef=document.getElementById('tr_new_ref').value.trim();
  if(!newRef){alert('Veuillez saisir le nouveau bureau dans l\'onglet ① Informations.');return;}
  const fileInput=document.getElementById('trUploadFile');
  document.getElementById('tr_etage_h').value=trBureau.etage;
  document.getElementById('tr_id_h').value=trBureau.id;
  document.getElementById('tr_old_h').value=trBureau.ref_bureau||'';
  document.getElementById('tr_new_h').value=newRef;
  document.getElementById('tr_pdf_b64_h').value='';
  const fa=document.getElementById('inp_statut_ancien');
  const fn=document.getElementById('inp_statut_nouveau');
  let inpA=document.createElement('input');inpA.type='hidden';inpA.name='statut_ancien';inpA.value=fa.value;
  let inpN=document.createElement('input');inpN.type='hidden';inpN.name='statut_nouveau';inpN.value=fn.value;
  const form=document.getElementById('transferForm');
  form.querySelectorAll('input[name="statut_ancien"],input[name="statut_nouveau"]').forEach(e=>e.remove());
  form.appendChild(inpA);form.appendChild(inpN);
  /* Si fichier uploadé, utiliser FormData + fetch pour envoyer le fichier */
  if(fileInput.files[0]){
    const fd=new FormData(form);
    fd.set('decision_file',fileInput.files[0]);
    fd.delete('pdf_base64');
    const resp=await fetch('',{method:'POST',body:fd});
    location.href=resp.url||location.href;
  } else {
    form.submit();
  }
}

function trFileSelected(input){
  trUploadedFile=input.files[0];if(!trUploadedFile)return;
  document.getElementById('trDropLabel').textContent='✓ '+trUploadedFile.name;
  const url=URL.createObjectURL(trUploadedFile);const isPdf=trUploadedFile.type==='application/pdf';
  const wrap=document.getElementById('trFilePreview');
  wrap.innerHTML=isPdf?`<iframe src="${url}" style="width:100%;height:280px;border:none;border-radius:6px;"></iframe>`:`<img src="${url}" style="max-width:100%;border-radius:6px;">`;
  wrap.style.display='block';
}

/* ────────── Standard modals ────────── */
function openAdd(){document.getElementById('addEtage').value=curFloor;document.getElementById('addFloorLbl').textContent=ECFG[curFloor]?.label||'';openModal('addModal');}
function openEdit(id){const b=ALL.find(x=>x.id==id);if(!b)return;['ref_bureau','superficie','superficie_droit','mle','nom','prenom','emploi','l_entite','direction','direction_centrale','l_classf','notes'].forEach(k=>{const el=document.getElementById('e_'+k);if(el)el.value=b[k]||'';});const ss=document.getElementById('e_statut');if(ss)ss.value=b.statut||'Occupé';const sf=document.getElementById('e_l_fonct');if(sf)sf.value=b.l_fonct||'';document.getElementById('eId').value=id;document.getElementById('eEtage').value=b.etage;openModal('editModal');}
function openDel(id,lbl,e){document.getElementById('delId').value=id;document.getElementById('delEtage').value=e;document.getElementById('delLbl').textContent='Bureau '+lbl;openModal('deleteModal');}
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
function openLB(src){document.getElementById('lbImg').src=src;document.getElementById('lightbox').classList.add('open');}
function closeLB(){document.getElementById('lightbox').classList.remove('open');document.getElementById('lbImg').src='';}


const PLAN_MAPS = {
  0: [
    /* ── Zone E (escaliers / bureaux hauts) ── */
    {ref:'0E01', coords:'1259,61,1306,103',   shape:'rect'},
    {ref:'0E02', coords:'1205,61,1257,106',   shape:'rect'},
    {ref:'0E03', coords:'1116,60,1200,106',   shape:'rect'},
    {ref:'0E07', coords:'822,62,947,103',     shape:'rect'},
    {ref:'0E08', coords:'859,133,951,184',    shape:'rect'},
    {ref:'0E13', coords:'1138,133,1199,184,1165,290,1199,184', shape:'poly'},
    {ref:'0E14', coords:'1200,131,1233,183,1225,223,1200,131', shape:'poly'},
    {ref:'0E15', coords:'1238,131,1273,184,1261,269,1238,131', shape:'poly'},

    /* ── Zone D ── */
    {ref:'0D01', coords:'1385,100,1438,182',  shape:'rect'},
    {ref:'0D02', coords:'1384,208,1438,252',  shape:'rect'},
    {ref:'0D04', coords:'1306,286,1438,339',  shape:'rect'},
    {ref:'0D06', coords:'1317,530,1359,561',  shape:'rect'},
    {ref:'0D07', coords:'1305,484,1347,529',  shape:'rect'},
    {ref:'0D09', coords:'1305,253,1438,286',  shape:'rect'},
    {ref:'0D10', coords:'1307,221,1346,250',  shape:'rect'},

    /* ── Zone F ── */
    {ref:'0F01', coords:'769,192,828,264',    shape:'rect'},
    {ref:'0F02', coords:'764,264,812,302',    shape:'rect'},
    {ref:'0F03', coords:'763,302,828,342',    shape:'rect'},
    {ref:'0F04', coords:'765,343,828,380',    shape:'rect'},
    {ref:'0F05', coords:'764,382,829,423',    shape:'rect'},
    {ref:'0F08', coords:'857,370,925,419',    shape:'rect'},
    {ref:'0F09', coords:'858,221,926,368',    shape:'rect'},
    {ref:'0F10', coords:'857,220,924,322',    shape:'rect'},

    /* ── Zone A ── */
    {ref:'0A01', coords:'809,973,903,1068',   shape:'rect'},
    {ref:'0A02', coords:'1037,947,1147,1028', shape:'rect'},
    {ref:'0A03', coords:'1034,1032,1128,1069',shape:'rect'},

    /* ── Zone B ── */
    {ref:'0B01', coords:'1167,1030,1249,1071',shape:'rect'},
    {ref:'0B02', coords:'1169,939,1226,1010', shape:'rect'},
    {ref:'0B03', coords:'1167,909,1226,936',  shape:'rect'},
    {ref:'0B04', coords:'1169,881,1228,910',  shape:'rect'},
    {ref:'0B05', coords:'1167,850,1209,880',  shape:'rect'},
    {ref:'0B06', coords:'1169,824,1209,854',  shape:'rect'},
    {ref:'0B07', coords:'1166,795,1225,822',  shape:'rect'},
    {ref:'0B08', coords:'1168,765,1226,794',  shape:'rect'},
    {ref:'0B09', coords:'1169,701,1225,762',  shape:'rect'},
    {ref:'0B10', coords:'1254,700,1310,765',  shape:'rect'},
    {ref:'0B11', coords:'1250,764,1310,794',  shape:'rect'},
    {ref:'0B13', coords:'1254,824,1309,880',  shape:'rect'},
    {ref:'0B14', coords:'1250,940,1298,973',  shape:'rect'},

    /* ── Zone G ── */
    {ref:'0G01', coords:'611,461,651,497',    shape:'rect'},
    {ref:'0G02', coords:'532,453,610,496',    shape:'rect'},
    {ref:'0G03', coords:'437,455,527,494',    shape:'rect'},
    {ref:'0G04', coords:'389,452,437,497',    shape:'rect'},
    {ref:'0G05', coords:'301,453,389,497',    shape:'rect'},
    {ref:'0G06', coords:'255,464,300,496',    shape:'rect'},
    {ref:'0G08', coords:'185,455,295,561',    shape:'rect'},
    {ref:'0G09', coords:'297,519,337,558',    shape:'rect'},
    {ref:'0G10', coords:'337,518,376,560',    shape:'rect'},
    {ref:'0G11', coords:'377,518,418,557',    shape:'rect'},
    {ref:'0G12', coords:'419,518,464,561',    shape:'rect'},
    {ref:'0G13', coords:'462,516,559,557',    shape:'rect'},
    {ref:'0G14', coords:'561,518,596,557',    shape:'rect'},
    {ref:'0G15', coords:'597,518,633,544',    shape:'rect'},

    /* ── Zone H ── */
    {ref:'0H01', coords:'121,558,178,591',    shape:'rect'},
    {ref:'0H03', coords:'124,633,182,667',    shape:'rect'},
    {ref:'0H04', coords:'107,669,181,717',    shape:'rect'},
    {ref:'0H05', coords:'124,739,178,777',    shape:'rect'},
    {ref:'0H06', coords:'120,799,178,873',    shape:'rect'},
    {ref:'0H07', coords:'120,876,180,910',    shape:'rect'},
    {ref:'0H10', coords:'205,841,255,873',    shape:'rect'},
    {ref:'0H11', coords:'209,798,262,839',    shape:'rect'},
    {ref:'0H12', coords:'209,754,262,796',    shape:'rect'},
    {ref:'0H13', coords:'208,713,264,754',    shape:'rect'},
    {ref:'0H14', coords:'205,669,264,712',    shape:'rect'},
    {ref:'0H15', coords:'206,591,263,668',    shape:'rect'},
    {ref:'0H17', coords:'206,559,256,587',    shape:'rect'},

    /* ── Zone I ── */
    {ref:'0I02', coords:'65,802,124,844',     shape:'rect'},

    /* ── Zone J ── */
    {ref:'0J01', coords:'260,873,307,912',    shape:'rect'},
    {ref:'0J02', coords:'308,872,347,909',    shape:'rect'},
    {ref:'0J03', coords:'349,872,392,912',    shape:'rect'},
    {ref:'0J04', coords:'394,872,430,912',    shape:'rect'},
    {ref:'0J05', coords:'431,873,470,910',    shape:'rect'},
    {ref:'0J06', coords:'469,872,589,909',    shape:'rect'},
    {ref:'0J07', coords:'589,882,626,910',    shape:'rect'},
    {ref:'0J08', coords:'587,936,626,970',    shape:'rect'},
    {ref:'0J09', coords:'513,936,589,973',    shape:'rect'},
    {ref:'0J10', coords:'473,938,512,973',    shape:'rect'},
    {ref:'0J11', coords:'440,934,475,971',    shape:'rect'},
    {ref:'0J12', coords:'369,936,441,974',    shape:'rect'},
    {ref:'0J13', coords:'300,937,368,973',    shape:'rect'},
    {ref:'0J14', coords:'263,937,301,965',    shape:'rect'},

    /* ── Zone K ── */
    {ref:'0K02', coords:'627,936,680,974',    shape:'rect'},
    {ref:'0K03', coords:'625,843,684,909',    shape:'rect'},
    {ref:'0K04', coords:'627,809,683,841',    shape:'rect'},
    {ref:'0K05', coords:'626,745,682,810',    shape:'rect'},
    {ref:'0K06', coords:'625,672,771,763',    shape:'rect'},
    {ref:'0K07', coords:'623,674,682,704',    shape:'rect'},
    {ref:'0K09', coords:'710,796,768,823',    shape:'rect'},
    {ref:'0K12', coords:'710,944,771,975',    shape:'rect'},
    {ref:'0K13', coords:'710,672,756,700',    shape:'rect'},
    {ref:'0K14', coords:'714,766,772,796',    shape:'rect'},
  ]
  /* Ajoutez d'autres étages ici : 2:[...], 3:[...] */
  ,1: [
    /* ── Zone E ── */
    {ref:'1E01', coords:'1249,64,1305,101',   shape:'rect'},
    {ref:'1E02', coords:'956,64,1009,99',     shape:'rect'},
    {ref:'1E03', coords:'922,73,956,103',     shape:'rect'},
    {ref:'1E04', coords:'825,64,918,99',      shape:'rect'},
    {ref:'1E05', coords:'858,127,924,188',    shape:'rect'},
    {ref:'1E06', coords:'961,131,1032,188',   shape:'rect'},
    {ref:'1E07', coords:'1038,90,1152,189',   shape:'rect'},
    {ref:'1E08', coords:'1153,89,1224,188',   shape:'rect'},
    {ref:'1E09', coords:'1225,133,1271,186',  shape:'rect'},
    {ref:'1E10', coords:'1271,131,1311,168',  shape:'rect'},

    /* ── Zone D ── */
    {ref:'1D01', coords:'1321,550,1358,575',  shape:'rect'},
    {ref:'1D02', coords:'1307,477,1358,546',  shape:'rect'},
    {ref:'1D03', coords:'1306,432,1360,476',  shape:'rect'},
    {ref:'1D04', coords:'1309,390,1358,431',  shape:'rect'},
    {ref:'1D05', coords:'1307,318,1359,386',  shape:'rect'},
    {ref:'1D06', coords:'1305,228,1359,270',  shape:'rect'},
    {ref:'1D07', coords:'1305,187,1359,224',  shape:'rect'},
    {ref:'1D08', coords:'1388,101,1437,186',  shape:'rect'},
    {ref:'1D09', coords:'1388,189,1427,223',  shape:'rect'},
    {ref:'1D10', coords:'1387,227,1440,262',  shape:'rect'},
    {ref:'1D11', coords:'1387,267,1437,337',  shape:'rect'},
    {ref:'1D12', coords:'1386,338,1441,399',  shape:'rect'},
    {ref:'1D13', coords:'1385,398,1440,444',  shape:'rect'},
    {ref:'1D14', coords:'1388,441,1436,492',  shape:'rect'},
    {ref:'1D15', coords:'1387,493,1438,543',  shape:'rect'},
    {ref:'1D16', coords:'1388,543,1432,576',  shape:'rect'},

    /* ── Zone C ── */
    {ref:'1C01', coords:'1309,645,1388,682',  shape:'rect'},
    {ref:'1C02', coords:'1271,578,1358,619',  shape:'rect'},

    /* ── Zone B ── */
    {ref:'1B01', coords:'1173,1046,1253,1079',shape:'rect'},
    {ref:'1B02', coords:'1168,985,1224,1019', shape:'rect'},
    {ref:'1B03', coords:'1169,951,1224,981',  shape:'rect'},
    {ref:'1B04', coords:'1169,920,1225,951',  shape:'rect'},
    {ref:'1B05', coords:'1171,884,1226,917',  shape:'rect'},
    {ref:'1B06', coords:'1168,851,1208,883',  shape:'rect'},
    {ref:'1B07', coords:'1167,822,1205,848',  shape:'rect'},
    {ref:'1B08', coords:'1167,781,1226,823',  shape:'rect'},
    {ref:'1B09', coords:'1169,743,1225,779',  shape:'rect'},
    {ref:'1B10', coords:'1167,716,1226,743',  shape:'rect'},
    {ref:'1B11', coords:'1250,714,1311,742',  shape:'rect'},
    {ref:'1B12', coords:'1252,745,1310,779',  shape:'rect'},
    {ref:'1B13', coords:'1254,779,1311,837',  shape:'rect'},
    {ref:'1B14', coords:'1253,837,1309,894',  shape:'rect'},
    {ref:'1B15', coords:'1252,894,1309,953',  shape:'rect'},
    {ref:'1B16', coords:'1252,956,1295,986',  shape:'rect'},
    {ref:'1B17', coords:'1179,682,1226,714',  shape:'rect'},

    /* ── Zone A ── */
    {ref:'1A01', coords:'772,890,808,1019',   shape:'rect'},
    {ref:'1A02', coords:'772,1044,809,1073',  shape:'rect'},
    {ref:'1A03', coords:'806,987,903,1044',   shape:'rect'},
    {ref:'1A04', coords:'810,1044,904,1079',  shape:'rect'},
    {ref:'1A05', coords:'1058,987,1138,1043', shape:'rect'},
    {ref:'1A06', coords:'1059,1046,1171,1081',shape:'rect'},

    /* ── Zone K ── */
    {ref:'1K01', coords:'625,987,682,1048',   shape:'rect'},
    {ref:'1K02', coords:'626,946,682,985',    shape:'rect'},
    {ref:'1K03', coords:'625,867,682,916',    shape:'rect'},
    {ref:'1K04', coords:'625,831,683,865',    shape:'rect'},
    {ref:'1K05', coords:'626,802,671,830',    shape:'rect'},
    {ref:'1K06', coords:'625,775,671,800',    shape:'rect'},
    {ref:'1K08', coords:'627,720,682,771',    shape:'rect'},
    {ref:'1K09', coords:'635,686,682,716',    shape:'rect'},
    {ref:'1K10', coords:'711,685,772,810',    shape:'rect'},
    {ref:'1K12', coords:'714,811,771,888',    shape:'rect'},
    {ref:'1K13', coords:'711,888,771,930',    shape:'rect'},
    {ref:'1K14', coords:'725,929,771,955',    shape:'rect'},
    {ref:'1K15', coords:'712,955,771,987',    shape:'rect'},

    /* ── Zone F ── */
    {ref:'1F01', coords:'775,188,829,232',    shape:'rect'},
    {ref:'1F02', coords:'764,235,829,281',    shape:'rect'},
    {ref:'1F03', coords:'767,282,826,326',    shape:'rect'},
    {ref:'1F04', coords:'767,329,829,370',    shape:'rect'},
    {ref:'1F05', coords:'768,371,825,402',    shape:'rect'},
    {ref:'1F06', coords:'767,406,828,441',    shape:'rect'},
    {ref:'1F07', coords:'778,441,907,473',    shape:'rect'},
    {ref:'1F08', coords:'855,403,920,439',    shape:'rect'},
    {ref:'1F09', coords:'857,368,922,404',    shape:'rect'},
    {ref:'1F10', coords:'867,289,922,368',    shape:'rect'},
    {ref:'1F11', coords:'857,241,922,289',    shape:'rect'},
    {ref:'1F12', coords:'858,188,920,239',    shape:'rect'},

    /* ── Zone G ── */
    {ref:'1G01', coords:'617,476,651,504',    shape:'rect'},
    {ref:'1G02', coords:'551,465,610,505',    shape:'rect'},
    {ref:'1G03', coords:'509,466,551,508',    shape:'rect'},
    {ref:'1G04', coords:'456,464,506,505',    shape:'rect'},
    {ref:'1G05', coords:'345,467,454,505',    shape:'rect'},
    {ref:'1G06', coords:'300,466,341,506',    shape:'rect'},
    {ref:'1G07', coords:'255,477,300,506',    shape:'rect'},
    {ref:'1G08', coords:'178,467,251,505',    shape:'rect'},
    {ref:'1G09', coords:'254,530,295,572',    shape:'rect'},
    {ref:'1G10', coords:'296,530,341,572',    shape:'rect'},
    {ref:'1G11', coords:'341,531,413,571',    shape:'rect'},
    {ref:'1G12', coords:'415,529,456,571',    shape:'rect'},
    {ref:'1G13', coords:'456,533,507,572',    shape:'rect'},
    {ref:'1G14', coords:'509,531,597,571',    shape:'rect'},
    {ref:'1G15', coords:'598,533,633,562',    shape:'rect'},

    /* ── Zone H ── */
    {ref:'1H01', coords:'141,571,178,603',    shape:'rect'},
    {ref:'1H02', coords:'124,823,178,855',    shape:'rect'},
    {ref:'1H03', coords:'137,857,178,888',    shape:'rect'},
    {ref:'1H04', coords:'121,886,178,947',    shape:'rect'},
    {ref:'1H05', coords:'210,888,259,929',    shape:'rect'},
    {ref:'1H06', coords:'206,857,254,886',    shape:'rect'},
    {ref:'1H07', coords:'209,822,263,856',    shape:'rect'},
    {ref:'1H08', coords:'157,745,263,795',    shape:'rect'},
    {ref:'1H09', coords:'160,692,262,743',    shape:'rect'},
    {ref:'1H10', coords:'158,633,263,692',    shape:'rect'},
    {ref:'1H11', coords:'210,572,252,604',    shape:'rect'},

    /* ── Zone J ── */
    {ref:'1J01', coords:'260,886,316,929',    shape:'rect'},
    {ref:'1J02', coords:'271,949,308,979',    shape:'rect'},
    {ref:'1J03', coords:'307,947,352,989',    shape:'rect'},
    {ref:'1J04', coords:'352,946,392,989',    shape:'rect'},
    {ref:'1J05-1',coords:'393,949,435,990',   shape:'rect'},
    {ref:'1J05-2',coords:'439,949,494,989',   shape:'rect'},
    {ref:'1J06', coords:'498,949,539,990',    shape:'rect'},
    {ref:'1J07', coords:'541,949,585,990',    shape:'rect'},
    {ref:'1J08', coords:'586,949,625,981',    shape:'rect'},
    {ref:'1J09', coords:'588,898,625,930',    shape:'rect'},
    {ref:'1J10', coords:'541,888,586,929',    shape:'rect'},
    {ref:'1J11', coords:'503,888,540,928',    shape:'rect'},
    {ref:'1J12', coords:'463,888,504,929',    shape:'rect'},
    {ref:'1J13', coords:'431,886,462,921',    shape:'rect'},
    {ref:'1J14', coords:'394,886,429,920',    shape:'rect'},
    {ref:'1J15', coords:'357,886,393,930',    shape:'rect'},
    {ref:'1J16', coords:'316,888,357,928',    shape:'rect'},

    /* ── Zone NC (Nouveaux Cadres) ── */
    {ref:'1NC1', coords:'625,624,683,686',    shape:'rect'},
    {ref:'1NC2', coords:'720,657,769,682',    shape:'rect'},
    {ref:'1NC5', coords:'858,473,922,580',    shape:'rect'},
    {ref:'1NC6', coords:'706,465,755,506',    shape:'rect'},
    {ref:'1NC7', coords:'655,466,706,506',    shape:'rect'},
    {ref:'1NC9', coords:'719,617,769,656',    shape:'rect'},
  ]
  ,2: [
    /* ── Zone G ── */
    {ref:'2G05', coords:'181,417,254,462',   shape:'rect'},
    {ref:'2G04', coords:'256,429,299,460',   shape:'rect'},
    {ref:'2G03', coords:'300,421,341,461',   shape:'rect'},
    {ref:'2G06', coords:'258,486,368,529',   shape:'rect'},
    {ref:'2G07', coords:'368,453,462,533',   shape:'rect'},
    {ref:'2G09', coords:'466,454,520,530',   shape:'rect'},
    {ref:'2G10', coords:'560,490,601,533',   shape:'rect'},
    {ref:'2G11', coords:'600,488,642,521',   shape:'rect'},
    {ref:'2G02', coords:'553,419,614,465',   shape:'rect'},
    {ref:'2G01', coords:'616,428,651,462',   shape:'rect'},

    /* ── Zone NC ── */
    {ref:'2NC7', coords:'653,421,706,460',   shape:'rect'},
    {ref:'2NC6', coords:'708,423,755,460',   shape:'rect'},
    {ref:'2NC1', coords:'627,584,683,649',   shape:'rect'},
    {ref:'2NC2', coords:'722,615,768,644',   shape:'rect'},
    {ref:'2NC9', coords:'725,582,768,612',   shape:'rect'},
    {ref:'2NC3', coords:'780,579,907,643',   shape:'rect'},
    {ref:'2NC4', coords:'858,509,919,545',   shape:'rect'},

    /* ── Zone F ── */
    {ref:'2F01', coords:'769,175,825,272',   shape:'rect'},
    {ref:'2F02', coords:'767,277,813,315',   shape:'rect'},
    {ref:'2F03', coords:'765,319,814,351',   shape:'rect'},
    {ref:'2F04', coords:'771,356,824,392',   shape:'rect'},
    {ref:'2F05', coords:'777,396,824,425',   shape:'rect'},
    {ref:'2F06', coords:'861,358,919,391',   shape:'rect'},
    {ref:'2F07', coords:'862,264,920,351',   shape:'rect'},
    {ref:'2F08', coords:'862,171,920,262',   shape:'rect'},

    /* ── Zone E ── */
    {ref:'2E01', coords:'1270,66,1305,98',   shape:'rect'},
    {ref:'2E02', coords:'920,68,955,99',     shape:'rect'},
    {ref:'2E03', coords:'826,61,916,101',    shape:'rect'},
    {ref:'2E04', coords:'861,127,920,167',   shape:'rect'},
    {ref:'2E05', coords:'923,127,953,158',   shape:'rect'},
    {ref:'2E06', coords:'1028,87,1077,168',  shape:'rect'},
    {ref:'2E07', coords:'1082,86,1148,167',  shape:'rect'},
    {ref:'2E08', coords:'1155,89,1203,163',  shape:'rect'},
    {ref:'2E09', coords:'1208,129,1270,166', shape:'rect'},
    {ref:'2E10', coords:'1271,129,1303,156', shape:'rect'},
    {ref:'2E11', coords:'1229,58,1267,99',   shape:'rect'},
    {ref:'2E12', coords:'956,58,997,98',     shape:'rect'},
    {ref:'2E13', coords:'960,129,1021,168',  shape:'rect'},

    /* ── Zone D ── */
    {ref:'2D01', coords:'1315,505,1354,531', shape:'rect'},
    {ref:'2D02', coords:'1307,435,1359,498', shape:'rect'},
    {ref:'2D03', coords:'1308,338,1404,431', shape:'rect'},
    {ref:'2D04', coords:'1309,286,1404,335', shape:'rect'},
    {ref:'2D05', coords:'1308,251,1357,283', shape:'rect'},
    {ref:'2D06', coords:'1306,217,1358,250', shape:'rect'},
    {ref:'2D07', coords:'1305,168,1358,217', shape:'rect'},
    {ref:'2D08', coords:'1385,98,1436,167',  shape:'rect'},
    {ref:'2D09', coords:'1389,169,1425,197', shape:'rect'},
    {ref:'2D10', coords:'1389,200,1437,224', shape:'rect'},
    {ref:'2D11', coords:'1388,227,1437,260', shape:'rect'},
    {ref:'2D12', coords:'1388,461,1437,498', shape:'rect'},
    {ref:'2D13', coords:'1388,502,1429,535', shape:'rect'},

    /* ── Zone C ── */
    {ref:'2C01', coords:'913,609,949,635',   shape:'rect'},
    {ref:'2C02', coords:'953,608,1004,644',  shape:'rect'},
    {ref:'2C03', coords:'1005,608,1090,644', shape:'rect'},
    {ref:'2C04', coords:'1091,616,1128,648', shape:'rect'},
    {ref:'2C05', coords:'1128,607,1172,647', shape:'rect'},
    {ref:'2C06', coords:'1253,608,1305,645', shape:'rect'},
    {ref:'2C07', coords:'1307,610,1385,645', shape:'rect'},
    {ref:'2C08', coords:'1305,537,1359,576', shape:'rect'},
    {ref:'2C09', coords:'1210,538,1305,576', shape:'rect'},
    {ref:'2C10', coords:'1161,535,1208,578', shape:'rect'},
    {ref:'2C11', coords:'1115,535,1161,576', shape:'rect'},
    {ref:'2C12', coords:'1063,535,1114,576', shape:'rect'},
    {ref:'2C13', coords:'1013,537,1061,576', shape:'rect'},
    {ref:'2C14', coords:'944,537,1012,576',  shape:'rect'},

    /* ── Zone K ── */
    {ref:'2K01', coords:'627,966,680,1031',  shape:'rect'},
    {ref:'2K02', coords:'626,922,683,963',   shape:'rect'},
    {ref:'2K03', coords:'627,837,682,894',   shape:'rect'},
    {ref:'2K04', coords:'629,799,680,836',   shape:'rect'},
    {ref:'2K05', coords:'624,771,683,796',   shape:'rect'},
    {ref:'2K06', coords:'631,741,683,767',   shape:'rect'},
    {ref:'2K07', coords:'627,711,682,741',   shape:'rect'},
    {ref:'2K08', coords:'626,679,682,707',   shape:'rect'},
    {ref:'2K09', coords:'641,653,682,680',   shape:'rect'},
    {ref:'2K10', coords:'714,645,756,680',   shape:'rect'},
    {ref:'2K11', coords:'715,684,767,751',   shape:'rect'},
    {ref:'2K12', coords:'716,754,769,791',   shape:'rect'},
    {ref:'2K13', coords:'715,792,767,869',   shape:'rect'},
    {ref:'2K14', coords:'716,876,769,958',   shape:'rect'},

    /* ── Zone H ── */
    {ref:'2H01', coords:'145,532,177,559',   shape:'rect'},
    {ref:'2H02', coords:'124,564,178,604',   shape:'rect'},
    {ref:'2H03', coords:'127,610,226,690',   shape:'rect'},
    {ref:'2H04', coords:'125,700,217,783',   shape:'rect'},
    {ref:'2H05', coords:'124,790,178,823',   shape:'rect'},
    {ref:'2H06', coords:'137,827,177,855',   shape:'rect'},
    {ref:'2H07', coords:'124,860,176,920',   shape:'rect'},
    {ref:'2H08', coords:'207,860,260,901',   shape:'rect'},
    {ref:'2H09', coords:'211,827,243,853',   shape:'rect'},
    {ref:'2H10', coords:'210,533,254,583',   shape:'rect'},

    /* ── Zone J ── */
    {ref:'2J01', coords:'267,860,319,898',   shape:'rect'},
    {ref:'2J02', coords:'275,925,304,954',   shape:'rect'},
    {ref:'2J03', coords:'309,925,348,963',   shape:'rect'},
    {ref:'2J04', coords:'415,886,455,963',   shape:'rect'},
    {ref:'2J05', coords:'544,922,621,963',   shape:'rect'},
    {ref:'2J06', coords:'563,860,619,892',   shape:'rect'},

    /* ── Zone A ── */
    {ref:'2A01', coords:'772,966,808,999',   shape:'rect'},
    {ref:'2A02', coords:'810,958,857,999',   shape:'rect'},
    {ref:'2A03', coords:'1070,959,1128,996', shape:'rect'},
    {ref:'2A04', coords:'1130,969,1168,996', shape:'rect'},
    {ref:'2A05', coords:'1094,1027,1172,1064',shape:'rect'},
    {ref:'2A06', coords:'1042,1028,1094,1063',shape:'rect'},
    {ref:'2A07', coords:'973,985,1040,1064', shape:'rect'},
    {ref:'2A08', coords:'883,983,969,1063',  shape:'rect'},
    {ref:'2A09', coords:'845,1028,882,1064', shape:'rect'},
    {ref:'2A10', coords:'809,1028,845,1064', shape:'rect'},
    {ref:'2A11', coords:'771,1028,808,1055', shape:'rect'},

    /* ── Zone B ── */
    {ref:'2B01', coords:'1173,1028,1250,1064',shape:'rect'},
    {ref:'2B02', coords:'1168,957,1226,996', shape:'rect'},
    {ref:'2B03', coords:'1168,905,1224,957', shape:'rect'},
    {ref:'2B04', coords:'1168,850,1225,903', shape:'rect'},
    {ref:'2B05', coords:'1172,794,1224,851', shape:'rect'},
    {ref:'2B06', coords:'1168,746,1224,792', shape:'rect'},
    {ref:'2B07', coords:'1168,712,1225,746', shape:'rect'},
    {ref:'2B08', coords:'1169,677,1224,710', shape:'rect'},
    {ref:'2B09', coords:'1184,648,1226,676', shape:'rect'},
    {ref:'2B10', coords:'1252,648,1297,677', shape:'rect'},
    {ref:'2B11', coords:'1253,677,1310,749', shape:'rect'},
    {ref:'2B12', coords:'1254,750,1309,819', shape:'rect'},
    {ref:'2B13', coords:'1252,820,1299,852', shape:'rect'},
    {ref:'2B14', coords:'1253,856,1309,925', shape:'rect'},
    {ref:'2B15', coords:'1251,926,1294,958', shape:'rect'},
  ]
  ,3: [
    /* ── Zone H ── */
    {ref:'3H01', coords:'144,525,177,557',  shape:'rect'},
    {ref:'3H02', coords:'124,558,178,596',  shape:'rect'},
    {ref:'3H03', coords:'123,596,177,631',  shape:'rect'},
    {ref:'3H04', coords:'123,632,177,676',  shape:'rect'},
    {ref:'3H05', coords:'124,676,180,723',  shape:'rect'},
    {ref:'3H06', coords:'125,724,177,789',  shape:'rect'},
    {ref:'3H07', coords:'124,791,178,824',  shape:'rect'},
    {ref:'3H08', coords:'137,827,176,858',  shape:'rect'},
    {ref:'3H09', coords:'124,864,177,922',  shape:'rect'},
    {ref:'3H10', coords:'221,859,272,896',  shape:'rect'},
    {ref:'3H11', coords:'219,827,258,858',  shape:'rect'},
    {ref:'3H12', coords:'219,786,271,826',  shape:'rect'},
    {ref:'3H13', coords:'220,742,271,785',  shape:'rect'},
    {ref:'3H14', coords:'220,704,271,741',  shape:'rect'},
    {ref:'3H15', coords:'221,663,271,702',  shape:'rect'},
    {ref:'3H16', coords:'221,624,272,661',  shape:'rect'},
    {ref:'3H17', coords:'221,592,272,625',  shape:'rect'},
    {ref:'3H18', coords:'219,556,272,591',  shape:'rect'},

    /* ── Zone G ── */
    {ref:'3G01', coords:'613,422,653,456',  shape:'rect'},
    {ref:'3G02', coords:'555,413,612,457',  shape:'rect'},
    {ref:'3G03', coords:'519,412,552,457',  shape:'rect'},
    {ref:'3G04', coords:'256,424,299,456',  shape:'rect'},
    {ref:'3G05', coords:'182,412,256,457',  shape:'rect'},
    {ref:'3G06', coords:'264,481,329,527',  shape:'rect'},
    {ref:'3G09', coords:'426,445,480,526',  shape:'rect'},
    {ref:'3G10', coords:'480,481,551,527',  shape:'rect'},
    {ref:'3G11', coords:'553,482,592,526',  shape:'rect'},
    {ref:'3G12', coords:'593,481,633,513',  shape:'rect'},

    /* ── Zone NC ── */
    {ref:'3NC7', coords:'655,411,707,457',  shape:'rect'},
    {ref:'3NC6', coords:'708,412,755,456',  shape:'rect'},
    {ref:'3NC1', coords:'627,583,682,645',  shape:'rect'},
    {ref:'3NC9', coords:'720,574,771,614',  shape:'rect'},
    {ref:'3NC2', coords:'720,615,769,647',  shape:'rect'},

    /* ── Zone F ── */
    {ref:'3F01', coords:'764,163,828,274',  shape:'rect'},
    {ref:'3F02', coords:'765,274,816,310',  shape:'rect'},
    {ref:'3F03', coords:'767,311,816,346',  shape:'rect'},
    {ref:'3F04', coords:'767,345,828,387',  shape:'rect'},
    {ref:'3F05', coords:'776,388,828,421',  shape:'rect'},
    {ref:'3F06', coords:'859,348,920,419',  shape:'rect'},
    {ref:'3F07', coords:'858,310,922,347',  shape:'rect'},
    {ref:'3F08', coords:'857,261,922,311',  shape:'rect'},
    {ref:'3F09', coords:'857,213,922,262',  shape:'rect'},
    {ref:'3F10', coords:'857,114,922,213',  shape:'rect'},

    /* ── Zone E ── */
    {ref:'3E01', coords:'821,37,871,86',    shape:'rect'},
    {ref:'3E02', coords:'873,36,920,87',    shape:'rect'},
    {ref:'3E04', coords:'968,36,1016,86',   shape:'rect'},
    {ref:'3E05', coords:'1262,50,1303,85',  shape:'rect'},
    {ref:'3E06', coords:'1387,86,1438,156', shape:'rect'},
    {ref:'3E07', coords:'1245,117,1307,156',shape:'rect'},
    {ref:'3E08', coords:'1040,70,1242,158', shape:'rect'},
    {ref:'3NC5', coords:'853,421,919,480',  shape:'rect'},
    {ref:'3NC4', coords:'855,480,922,537',  shape:'rect'},

    /* ── Zone K ── */
    {ref:'3K01', coords:'626,965,683,1031', shape:'rect'},
    {ref:'3K02', coords:'625,934,682,963',  shape:'rect'},
    {ref:'3K03', coords:'627,898,683,933',  shape:'rect'},
    {ref:'3K04', coords:'623,868,683,897',  shape:'rect'},
    {ref:'3K05', coords:'628,839,683,868',  shape:'rect'},
    {ref:'3K06', coords:'626,800,684,836',  shape:'rect'},
    {ref:'3K07', coords:'627,770,680,798',  shape:'rect'},
    {ref:'3K08', coords:'625,739,682,770',  shape:'rect'},
    {ref:'3K09', coords:'626,710,682,738',  shape:'rect'},
    {ref:'3K10', coords:'626,678,682,709',  shape:'rect'},
    {ref:'3K11', coords:'641,649,680,680',  shape:'rect'},
    {ref:'3K12', coords:'714,678,771,751',  shape:'rect'},
    {ref:'3K13', coords:'711,753,768,808',  shape:'rect'},
    {ref:'3K14', coords:'716,808,768,836',  shape:'rect'},
    {ref:'3K15', coords:'711,839,768,868',  shape:'rect'},
    {ref:'3K16', coords:'712,868,771,896',  shape:'rect'},
    {ref:'3K17', coords:'712,898,769,929',  shape:'rect'},
    {ref:'3K18', coords:'712,930,768,959',  shape:'rect'},
    {ref:'3K19', coords:'714,649,757,678',  shape:'rect'},

    /* ── Zone A ── */
    {ref:'3A01', coords:'772,971,808,1000', shape:'rect'},
    {ref:'3A02', coords:'810,959,855,1000', shape:'rect'},
    {ref:'3A03', coords:'1102,971,1140,1000',shape:'rect'},
    {ref:'3A04', coords:'1142,963,1188,999',shape:'rect'},
    {ref:'3A05', coords:'1142,1026,1230,1064',shape:'rect'},
    {ref:'3A06', coords:'1104,1026,1140,1053',shape:'rect'},
    {ref:'3A07', coords:'1067,1027,1103,1064',shape:'rect'},
    {ref:'3A08', coords:'1029,1026,1065,1064',shape:'rect'},
    {ref:'3A09', coords:'959,1026,1029,1064',shape:'rect'},
    {ref:'3A10', coords:'910,1026,959,1063', shape:'rect'},
    {ref:'3A11', coords:'873,1026,910,1065', shape:'rect'},
    {ref:'3A12', coords:'769,1027,808,1053', shape:'rect'},
    {ref:'3A13', coords:'808,1027,873,1063', shape:'rect'},
    {ref:'3A14', coords:'855,962,894,999',   shape:'rect'},
    {ref:'3A15', coords:'895,963,943,999',   shape:'rect'},
    {ref:'3A16', coords:'944,963,1028,1000', shape:'rect'},
    {ref:'3A17', coords:'1029,959,1066,1002',shape:'rect'},
    {ref:'3A18', coords:'1067,963,1103,1002',shape:'rect'},

    /* ── Zone C ── */
    {ref:'3C01', coords:'1137,531,1188,572', shape:'rect'},
    {ref:'3C02', coords:'1189,531,1228,561', shape:'rect'},
    {ref:'3C03', coords:'1229,530,1267,572', shape:'rect'},
    {ref:'3C04', coords:'1269,531,1320,572', shape:'rect'},
    {ref:'3C05', coords:'1226,606,1344,644', shape:'rect'},
    {ref:'3C06', coords:'1106,606,1153,643', shape:'rect'},
    {ref:'3C07', coords:'994,561,1106,643',  shape:'rect'},
    {ref:'3C08', coords:'912,574,993,644',   shape:'rect'},
  ]
  ,4: [
    /* ── Zone G ── */
    {ref:'4G01', coords:'527,490,574,530',   shape:'rect'},
    {ref:'4G02', coords:'431,481,527,531',   shape:'rect'},
    {ref:'4G03', coords:'380,480,429,534',   shape:'rect'},
    {ref:'4G04', coords:'337,482,381,522',   shape:'rect'},
    {ref:'4G05', coords:'290,482,333,523',   shape:'rect'},
    {ref:'4G06', coords:'230,481,288,530',   shape:'rect'},
    {ref:'4G07', coords:'176,481,230,533',   shape:'rect'},
    {ref:'4G08', coords:'115,481,174,533',   shape:'rect'},
    {ref:'4G09', coords:'117,568,173,619',   shape:'rect'},
    {ref:'4G10', coords:'176,570,229,620',   shape:'rect'},
    {ref:'4G11', coords:'231,568,287,617',   shape:'rect'},
    {ref:'4G12', coords:'290,570,369,620',   shape:'rect'},
    {ref:'4G13', coords:'368,568,429,617',   shape:'rect'},
    {ref:'4G14', coords:'431,570,504,620',   shape:'rect'},
    {ref:'4G15', coords:'508,570,553,607',   shape:'rect'},

    /* ── Zone K ── */
    {ref:'4K01', coords:'545,1057,613,1096', shape:'rect'},
    {ref:'4K02', coords:'543,998,613,1055',  shape:'rect'},
    {ref:'4K03', coords:'541,963,597,996',   shape:'rect'},
    {ref:'4K04', coords:'543,908,613,962',   shape:'rect'},
    {ref:'4K05', coords:'544,822,610,905',   shape:'rect'},
    {ref:'4K06', coords:'559,762,612,798',   shape:'rect'},
    {ref:'4K07', coords:'644,767,698,804',   shape:'rect'},
    {ref:'4K08', coords:'645,806,716,849',   shape:'rect'},
    {ref:'4K09', coords:'643,850,716,889',   shape:'rect'},
    {ref:'4K10', coords:'643,890,718,945',   shape:'rect'},
    {ref:'4K11', coords:'645,946,716,991',   shape:'rect'},
    {ref:'4K12', coords:'643,989,716,1038',  shape:'rect'},
    {ref:'4K13', coords:'645,1038,715,1096', shape:'rect'},

    /* ── Zone NC ── */
    {ref:'4NC1', coords:'541,685,613,761',   shape:'rect'},
    {ref:'4NC3', coords:'716,681,890,769',   shape:'rect'},
    {ref:'4NC4', coords:'820,575,900,625',   shape:'rect'},
    {ref:'4NC6', coords:'642,482,702,531',   shape:'rect'},
    {ref:'4NC7', coords:'577,481,641,531',   shape:'rect'},
    {ref:'4NC9', coords:'654,682,714,729',   shape:'rect'},

    /* ── Zone F ── */
    {ref:'4F01', coords:'716,159,775,203',   shape:'rect'},
    {ref:'4F02', coords:'707,204,776,250',   shape:'rect'},
    {ref:'4F03', coords:'707,249,776,297',   shape:'rect'},
    {ref:'4F04', coords:'708,297,775,350',   shape:'rect'},
    {ref:'4F05', coords:'708,348,775,437',   shape:'rect'},
    {ref:'4F06', coords:'718,439,775,478',   shape:'rect'},
    {ref:'4F07', coords:'817,388,900,440',   shape:'rect'},
    {ref:'4F08', coords:'818,344,900,390',   shape:'rect'},
    {ref:'4F09', coords:'823,298,902,347',   shape:'rect'},
    {ref:'4F10', coords:'819,252,901,297',   shape:'rect'},
    {ref:'4F11', coords:'814,197,903,250',   shape:'rect'},
    {ref:'4F12', coords:'826,105,900,196',   shape:'rect'},
    {ref:'4F13', coords:'842,30,902,103',    shape:'rect'},
    {ref:'4F14', coords:'777,28,839,85',     shape:'rect'},

    /* ── Zone C ── */
    {ref:'4C01', coords:'945,625,1001,680',  shape:'rect'},
    {ref:'4C02', coords:'1002,614,1063,681', shape:'rect'},
    {ref:'4C03', coords:'1062,613,1124,681', shape:'rect'},
    {ref:'4C04', coords:'1123,614,1185,680', shape:'rect'},
    {ref:'4C05', coords:'1187,612,1245,665', shape:'rect'},
    {ref:'4C06', coords:'1246,612,1313,681', shape:'rect'},
    {ref:'4C07', coords:'1245,704,1310,773', shape:'rect'},
    {ref:'4C08', coords:'1187,704,1245,773', shape:'rect'},
    {ref:'4C09', coords:'1123,706,1185,773', shape:'rect'},
    {ref:'4C10', coords:'1051,706,1123,773', shape:'rect'},
    {ref:'4C11', coords:'953,708,1050,773',  shape:'rect'},
    {ref:'4C12', coords:'891,706,952,755',   shape:'rect'},
    {ref:'4C13', coords:'1314,612,1392,681', shape:'rect'},
  ]
  ,5: [
    /* ── Zone F ── */
    {ref:'5F01', coords:'819,83,904,195',   shape:'rect'},
    {ref:'5F03', coords:'824,196,903,250',  shape:'rect'},
    {ref:'5F04', coords:'831,253,903,307',  shape:'rect'},
    {ref:'5F05', coords:'951,255,1020,305', shape:'rect'},
    {ref:'5F06', coords:'948,193,1034,254', shape:'rect'},
    {ref:'5F07', coords:'965,143,1034,195', shape:'rect'},
    {ref:'5F08', coords:'951,85,1033,143',  shape:'rect'},

    /* ── Zone NC ── */
    {ref:'5NC2',  coords:'630,627,706,681', shape:'rect'},
    {ref:'5NC3',  coords:'761,629,845,678', shape:'rect'},
    {ref:'5NC10', coords:'588,309,696,390', shape:'rect'},
    {ref:'5NC6',  coords:'951,305,1038,391',shape:'rect'},
    {ref:'5NC',   coords:'626,586,682,627', shape:'rect'},

    /* ── Zone C ── */
    {ref:'5C01', coords:'1082,505,1173,583', shape:'rect'},
    {ref:'5C02', coords:'1175,505,1266,578', shape:'rect'},
    {ref:'5C03', coords:'1271,504,1363,580', shape:'rect'},
    {ref:'5C04', coords:'1181,615,1359,685', shape:'rect'},
    {ref:'5C05', coords:'1099,615,1175,684', shape:'rect'},
    {ref:'5C06', coords:'1030,614,1097,670', shape:'rect'},

    /* ── Zone K ── */
    {ref:'5K03', coords:'707,682,805,726',  shape:'rect'},
  ]
};

/* ────────── Tooltip bureau ────────── */
function _createMapTooltip(){
  let t=document.getElementById('_mapTip');
  if(!t){
    t=document.createElement('div');t.id='_mapTip';
    t.style.cssText=[
      'position:fixed','z-index:9999','pointer-events:none','display:none',
      'background:#ffffff','color:#1A1A18',
      'font-family:DM Sans,sans-serif',
      'font-size:12px','line-height:1.5',
      'border-radius:12px',
      'box-shadow:0 8px 32px rgba(0,0,0,.16),0 2px 8px rgba(0,0,0,.08)',
      'border:1px solid rgba(0,0,0,.07)',
      'min-width:180px','max-width:240px',
      'padding:0','overflow:hidden',
      'transition:opacity .12s ease',
      'opacity:0'
    ].join(';');
    /* petit style d'animation via une règle CSS injectée */
    if(!document.getElementById('_mapTipStyle')){
      const s=document.createElement('style');s.id='_mapTipStyle';
      s.textContent='#_mapTip.tip-vis{opacity:1!important}';
      document.head.appendChild(s);
    }
    document.body.appendChild(t);
  }
  return t;
}

function _showTooltip(tip, ref, bureau, isHn){
  const occ = bureau && bureau.mle && String(bureau.mle).trim();
  const st  = bureau ? (bureau.statut || (occ ? 'Occupé' : 'Libre')) : null;
  const isOccSt = (st||'').toLowerCase().includes('occ');
  const isLibSt = (st||'').toLowerCase().includes('lib');
  const stColor = !bureau ? '#6B7280' : isOccSt ? '#1D4ED8' : isLibSt ? '#15803D' : '#6B7280';
  const stBg    = !bureau ? '#F3F4F6' : isOccSt ? '#DBEAFE'  : isLibSt ? '#DCFCE7'  : '#F3F4F6';
  const stDot   = !bureau ? '#9CA3AF' : isOccSt ? '#3B82F6'  : isLibSt ? '#22C55E'  : '#9CA3AF';

  let body = '';
  if(bureau && occ){
    const nm = [bureau.nom, bureau.prenom].filter(Boolean).join(' ');
    if(nm) body += `<div style="font-weight:700;font-size:13px;color:#111827;margin-bottom:1px">${esc(nm)}</div>`;
    if(bureau.mle) body += `<div style="font-size:10px;color:#9CA3AF;font-family:monospace;letter-spacing:.04em;margin-bottom:4px">${esc(bureau.mle)}</div>`;
    const dir = bureau.direction_centrale||bureau.direction||'';
    if(dir) body += `<div style="font-size:11px;color:#6B7280;display:flex;align-items:center;gap:4px"><span style="opacity:.5">▸</span>${esc(dir)}</div>`;
    const fn = bureau.emploi||bureau.l_fonct||'';
    if(fn)  body += `<div style="font-size:11px;color:#6B7280;display:flex;align-items:center;gap:4px"><span style="opacity:.5">▸</span>${esc(fn)}</div>`;
  } else if(bureau){
    body += `<div style="font-size:12px;color:#9CA3AF;font-style:italic;padding:2px 0">Aucun occupant</div>`;
  } else {
    body += `<div style="font-size:11px;color:#D1D5DB;font-style:italic">Non enregistré dans la base</div>`;
  }

  const sup = bureau&&bureau.superficie ? `<span style="font-size:10px;font-weight:600;color:rgba(255,255,255,.85);background:rgba(255,255,255,.15);padding:1px 7px;border-radius:20px">${parseFloat(bureau.superficie).toFixed(1)} m²</span>` : '';

  tip.innerHTML = `
    <div style="background:linear-gradient(135deg,#C8102E,#9B0E23);padding:9px 12px;display:flex;align-items:center;justify-content:space-between;gap:8px">
      <div style="display:flex;align-items:center;gap:7px">
        <span style="width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.6);flex-shrink:0"></span>
        <span style="font-size:14px;font-weight:800;color:#fff;letter-spacing:.04em;font-family:monospace">${ref}</span>
      </div>
      ${sup}
    </div>
    <div style="padding:10px 13px 11px">
      ${body}
      <div style="margin-top:8px;display:flex;align-items:center;gap:6px">
        <span style="width:7px;height:7px;border-radius:50%;background:${stDot};flex-shrink:0"></span>
        <span style="font-size:11px;font-weight:700;color:${stColor}">${st||'—'}${isHn?' · <span style="color:#D97706">⚠ Norme</span>':''}</span>
      </div>
    </div>`;
  tip.style.display='block';
  requestAnimationFrame(()=>tip.classList.add('tip-vis'));
}

function _hideTooltip(tip){
  tip.classList.remove('tip-vis');
  setTimeout(()=>{ if(!tip.classList.contains('tip-vis')) tip.style.display='none'; }, 120);
}

/* ────────── Rescale coords from native image size to displayed size ── */
function _scaledCoords(coords, shape, img){
  const scaleX = img.clientWidth  / img.naturalWidth;
  const scaleY = img.clientHeight / img.naturalHeight;
  const rect   = img.getBoundingClientRect();
  const nums   = coords.split(',').map(Number);
  if(shape==='rect'){
    return {
      left:   rect.left + nums[0]*scaleX,
      top:    rect.top  + nums[1]*scaleY,
      width:  (nums[2]-nums[0])*scaleX,
      height: (nums[3]-nums[1])*scaleY,
    };
  }
  /* poly */
  const pts=[];
  for(let i=0;i<nums.length-1;i+=2) pts.push([rect.left+nums[i]*scaleX, rect.top+nums[i+1]*scaleY]);
  return {poly:pts};
}

/* ────────── Build SVG overlay for interactive map ────────── */
function _buildMapOverlay(img, areas){
  const wrap = img.parentElement;
  let svg = wrap.querySelector('.pe-map-svg');
  if(svg) svg.remove();

  svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
  svg.classList.add('pe-map-svg');
  svg.style.cssText='position:absolute;inset:0;width:100%;height:100%;pointer-events:none;overflow:visible;';
  wrap.style.position='relative';
  wrap.appendChild(svg);

  const tip = _createMapTooltip();
  const scaleX = img.clientWidth  / img.naturalWidth;
  const scaleY = img.clientHeight / img.naturalHeight;

  areas.forEach(area=>{
    const nums = area.coords.split(',').map(Number);
    const ref  = area.ref;
    const bureau = (byE[curFloor]||[]).find(b=>(b.ref_bureau||'').toUpperCase()===ref.toUpperCase());
    const isHn = bureau && isHN(bureau);

    let el;
    if(area.shape==='rect'){
      el = document.createElementNS('http://www.w3.org/2000/svg','rect');
      el.setAttribute('x',      nums[0]*scaleX);
      el.setAttribute('y',      nums[1]*scaleY);
      el.setAttribute('width',  Math.max(4,(nums[2]-nums[0])*scaleX));
      el.setAttribute('height', Math.max(4,(nums[3]-nums[1])*scaleY));
    } else {
      const pts=[];
      for(let i=0;i<nums.length-1;i+=2) pts.push(nums[i]*scaleX+','+nums[i+1]*scaleY);
      el = document.createElementNS('http://www.w3.org/2000/svg','polygon');
      el.setAttribute('points', pts.join(' '));
    }

    /* ── Couleur selon statut du bureau ── */
    let fillHoverColor;
    if(!bureau){
      fillHoverColor = 'rgba(107,114,128,0.25)';
    } else {
      const st = (bureau.statut || (bureau.mle && String(bureau.mle).trim() ? 'Occupé' : 'Libre')).toLowerCase();
      if(st.includes('lib')){
        fillHoverColor = 'rgba(21,128,61,0.35)';
      } else if(st.includes('occ')){
        fillHoverColor = isHn ? 'rgba(217,119,6,0.40)' : 'rgba(29,78,216,0.35)';
      } else if(st.includes('trav')){
        fillHoverColor = 'rgba(251,191,36,0.45)';
      } else {
        fillHoverColor = 'rgba(107,114,128,0.30)';
      }
    }
    el.setAttribute('fill',         'transparent');
    el.setAttribute('stroke',       'none');
    el.setAttribute('stroke-width', '0');
    el.style.cssText='pointer-events:all;cursor:pointer;transition:fill .15s;';
    if(bureau) el.dataset.bureauId = String(bureau.id);
    el.dataset.bureauRef = ref;
    el._strokeNormal= 'none';
    el._fillNormal  = 'transparent';
    el._fillHover   = fillHoverColor;
    if(bureau && String(bureau.id) === String(activeId)){
      el.setAttribute('stroke','none');
      el.setAttribute('stroke-width','0');
    }

    svg.appendChild(el);

    /* Hover */
    el.addEventListener('mouseenter', e=>{
      el.setAttribute('fill', el._fillHover);
      if(!(bureau && String(bureau.id)===String(activeId))){
        el.setAttribute('stroke-width','0');
      }
      _showTooltip(tip, ref, bureau, isHn);
      const tx=e.clientX+14, ty=e.clientY-8;
      tip.style.left=Math.min(tx, window.innerWidth-250)+'px';
      tip.style.top=Math.max(8, ty)+'px';
    });
    el.addEventListener('mousemove', e=>{
      const tx=e.clientX+14, ty=e.clientY-8;
      tip.style.left=Math.min(tx, window.innerWidth-250)+'px';
      tip.style.top=Math.max(8, ty)+'px';
    });
    el.addEventListener('mouseleave',()=>{
      el.setAttribute('fill', el._fillNormal);
      el.setAttribute('stroke', 'none');
      el.setAttribute('stroke-width','0');
      _hideTooltip(tip);
    });

    /* Click → ouvre le détail */
    el.addEventListener('click', ()=>{
      tip.style.display='none';
      if(bureau){
        selBureau(bureau.id);
      } else {
        alert('Bureau ' + ref + ' non trouvé dans la base de données.');
      }
    });

    svg.appendChild(el);
  });

  /* Resize observer pour rescaler l'overlay si la fenêtre change */
  if(wrap._peResizeObs) wrap._peResizeObs.disconnect();
  wrap._peResizeObs = new ResizeObserver(()=>_buildMapOverlay(img, areas));
  wrap._peResizeObs.observe(wrap);
}

/* ────────── Plan étage ────────── */
function showPlanEtage(){
  activeId=null;document.querySelectorAll('.bcard').forEach(c=>c.classList.remove('sel'));
  document.getElementById('emptyDet').style.display='none';
  document.getElementById('detCont').style.display='none';
  document.getElementById('planEtagePanel').style.display='block';

  const cfg       = ECFG[curFloor];
  const c1        = cfg.color.split(',')[0], c2=cfg.color.split(',')[1];
  const etageLabel= cfg.label;
  const mapAreas  = PLAN_MAPS[curFloor]||[];
  const hasMap    = mapAreas.length > 0;

  /* Source image : uniquement les images intégrées dans le code */
  const imgSrc = (EMBEDDED_PLANS && EMBEDDED_PLANS[curFloor]) ? EMBEDDED_PLANS[curFloor] : null;

  let html = `<div class="pe-header">
    <div>
      <div class="pe-title">Plan du ${esc(etageLabel)}</div>
      <div class="pe-sub">Plan architectural${hasMap?' · Survolez ou cliquez sur un bureau':''}</div>
    </div>
    <div class="pe-actions">
      <span class="pe-badge" style="background:linear-gradient(135deg,${c1},${c2})">${esc(cfg.short)}</span>
    </div>
  </div>
  ${hasMap ? `<div style="display:flex;gap:10px;flex-wrap:wrap;padding:8px 0 4px;margin-bottom:4px;">
    <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:#1A1A18;">
      <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:rgba(21,128,61,0.35);"></span>Libre
    </span>
    <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:#1A1A18;">
      <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:rgba(29,78,216,0.30);"></span>Occupé
    </span>
    <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:#1A1A18;">
      <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:rgba(217,119,6,0.30);"></span>Hors norme
    </span>
    <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:#1A1A18;">
      <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:rgba(251,191,36,0.35);"></span>En travaux
    </span>
    <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:#6B7280;">
      <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:rgba(107,114,128,0.15);"></span>Non enregistré
    </span>
  </div>` : ''}
  `;

  if(imgSrc){
    const imgId = 'peMapImg_'+Date.now();
    html += `<div class="pe-img-wrap" id="peMapWrap" style="border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);border:1.5px solid var(--rule);">
      <img id="${imgId}" src="${imgSrc}" alt="Plan ${esc(etageLabel)}"
           style="width:100%;display:block;cursor:${hasMap?'crosshair':'default'}">
    </div>`;
  } else {
    html += `<div class="pe-placeholder">
      <div class="pe-ph-icon" style="background:${c1}18">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
          <rect x="3" y="3" width="18" height="18" rx="2" stroke="${c1}" stroke-width="1.5"/>
          <path d="M3 9h18M9 9v12M3 15h6" stroke="${c1}" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
      </div>
      <div class="pe-ph-title">Plan non disponible</div>
      <div class="pe-ph-sub">Le plan de cet étage n'est pas encore intégré.</div>
    </div>`;
  }

  document.getElementById('planEtageContent').innerHTML = html;

  /* Attach SVG overlay après chargement image */
  if(imgSrc && hasMap){
    const img = document.querySelector('#peMapWrap img');
    if(img){
      const attach = ()=>_buildMapOverlay(img, mapAreas);
      if(img.complete && img.naturalWidth) attach();
      else img.addEventListener('load', attach);
    }
  }
}

document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeModal('addModal');closeModal('editModal');closeModal('deleteModal');closeModal('transferModal');closeLB();if(openDD)closeDD(openDD);}});
document.querySelectorAll('.modal-bg').forEach(el=>el.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');}));

/* Forcer la réinitialisation si la page est restaurée depuis le cache navigateur */
window.addEventListener('pageshow', function(){
  activeId=null;
  document.getElementById('emptyDet').style.display='flex';
  document.getElementById('detCont').style.display='none';
  document.getElementById('planEtagePanel').style.display='none';
  document.querySelectorAll('.bcard').forEach(c=>c.classList.remove('sel'));
});
window.addEventListener('popstate', function(){
  activeId=null;
  document.getElementById('emptyDet').style.display='flex';
  document.getElementById('detCont').style.display='none';
  document.getElementById('planEtagePanel').style.display='none';
  document.querySelectorAll('.bcard').forEach(c=>c.classList.remove('sel'));
});

/* Nettoyer ?sel= de l'URL sans l'utiliser */
if(new URLSearchParams(location.search).get('sel')){
  const _u=new URL(location.href);_u.searchParams.delete('sel');
  history.replaceState(null,'',_u.toString());
}

switchFloor(curFloor,false);
updateFloorConflictBadges();
/* Forcer panneau vide au chargement */
activeId=null;
document.getElementById('emptyDet').style.display='flex';
document.getElementById('detCont').style.display='none';
document.getElementById('planEtagePanel').style.display='none';
</script>

<!-- ===== MODAL NOTIFICATIONS ===== -->
<div id="notifOverlay" style="display:none;" role="dialog" aria-modal="true" aria-label="Centre de notifications">
  <div id="notifModal">
    <div class="nm-head">
      <div class="nm-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#C8102E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        Notifications
        <span class="nm-badge" id="nmBadgeDanger" style="display:none;background:#FED7D7;color:#C53030;"></span>
        <span class="nm-badge" id="nmBadgeWarn" style="display:none;background:#FDE68A;color:#92400E;margin-left:4px;"></span>
      </div>
      <button class="nm-close" onclick="closeNotifModal()" aria-label="Fermer">&times;</button>
    </div>
    <div class="nm-tabs">
      <button class="nm-tab active" id="tabAll" onclick="filterNotif('all')">Tout <span id="cntAll"></span></button>
      <button class="nm-tab" id="tabInc" onclick="filterNotif('incoherence')">Incohérences <span id="cntInc" style="font-weight:400;color:#C53030;"></span></button>
      <button class="nm-tab" id="tabTrf" onclick="filterNotif('transfert')">Événements <span id="cntTrf" style="font-weight:400;color:#1D4ED8;"></span></button>
    </div>
    <div class="nm-body" id="nmBody"></div>
    <div class="nm-foot">
      <span class="nm-foot-note" id="nmFootNote"></span>
      <button onclick="closeNotifModal()" style="background:var(--red);color:white;border:none;padding:8px 20px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;">Fermer</button>
    </div>
  </div>
</div>

<script>
(function(){
  const RAW_NOTIFS = <?=($notifs_json??'[]')?>;
  let curFilter = 'all';

  const ICONS = {
    'alert-triangle': '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 22h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    'user-x': '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="17" y1="8" x2="23" y2="14"/><line x1="23" y1="8" x2="17" y2="14"/></svg>',
    'copy': '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
    'arrows-left-right': '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 11 21 7 17 3"/><line x1="21" y1="7" x2="3" y2="7"/><polyline points="7 21 3 17 7 13"/><line x1="3" y1="17" x2="21" y2="17"/></svg>',
    'transfer': '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>'
  };

  function renderList(filter){
    const body = document.getElementById('nmBody');
    const items = filter==='all' ? RAW_NOTIFS : RAW_NOTIFS.filter(n=>n.cat===filter);
    if(!items.length){
      body.innerHTML = '<div class="nm-empty">' + (filter==='transfert' ? 'Aucun transfert enregistr�.' : '\u2705 Aucune incoh�rence d�tect�e.') + '</div>';
      return;
    }
    body.innerHTML = items.map(n=>`
      <div class="nm-item ${n.type}">
        <div class="nm-icon">${ICONS[n.icon]||''}</div>
        <div style="flex:1;min-width:0;">
          <div class="nm-item-title">${n.titre}</div>
          <div class="nm-item-msg">${n.msg}</div>
        </div>
      </div>`).join('');
  }

  function updateBadges(){
    const danger  = RAW_NOTIFS.filter(n=>n.type==='danger').length;
    const warning = RAW_NOTIFS.filter(n=>n.type==='warning').length;
    const inc     = RAW_NOTIFS.filter(n=>n.cat==='incoherence').length;
    const trf     = RAW_NOTIFS.filter(n=>n.cat==='transfert').length;
    const total   = RAW_NOTIFS.length;

    const bellBadge = document.getElementById('notifBellCount');
    if(total>0){ bellBadge.textContent=total; bellBadge.style.display='flex'; }

    const bd = document.getElementById('nmBadgeDanger');
    if(danger>0){ bd.textContent=danger+' critique'+(danger>1?'s':''); bd.style.display=''; }
    const bw = document.getElementById('nmBadgeWarn');
    if(warning>0){ bw.textContent=warning+' alerte'+(warning>1?'s':''); bw.style.display=''; }

    document.getElementById('cntAll').textContent = total ? '('+total+')' : '';
    document.getElementById('cntInc').textContent = inc   ? '('+inc+')'  : '';
    document.getElementById('cntTrf').textContent = trf   ? '('+trf+')'  : '';
    document.getElementById('nmFootNote').textContent = 'Donn�es en temps r�el — ' + total + ' notification'+(total>1?'s':'');
  }

  window.filterNotif = function(f){
    curFilter = f;
    document.querySelectorAll('.nm-tab').forEach(t=>t.classList.remove('active'));
    document.getElementById('tab'+(f==='all'?'All':f==='incoherence'?'Inc':'Trf')).classList.add('active');
    renderList(f);
  };

  window.openNotifModal = function(){
    const ov = document.getElementById('notifOverlay');
    ov.style.display='flex';
    renderList(curFilter);
  };
  window.closeNotifModal = function(){
    document.getElementById('notifOverlay').style.display='none';
  };
  document.getElementById('notifOverlay').addEventListener('click', function(e){
    if(e.target===this) closeNotifModal();
  });
  document.addEventListener('keydown', e=>{ if(e.key==='Escape') closeNotifModal(); });

  updateBadges();

  // Auto-ouvrir au chargement s'il y a des alertes critiques (danger)
  const hasDanger = RAW_NOTIFS.some(n=>n.type==='danger');
  const hasSomething = RAW_NOTIFS.length > 0;
  if(hasSomething){
    if(hasDanger) curFilter = 'incoherence';
    setTimeout(openNotifModal, 600);
  }
})();
</script>
</body>
</html>