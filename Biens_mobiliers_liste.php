<?php
require_once 'config.php';
if(!isLoggedIn()){ redirect('login.php'); }
requireModuleAccess($pdo, 'biens_mobiliers');
$username = $_SESSION['username'] ?? 'Utilisateur';

$canCreate = hasModulePermission($pdo, 'biens_mobiliers', 'create');
$canUpdate = hasModulePermission($pdo, 'biens_mobiliers', 'update');
$canDelete = hasModulePermission($pdo, 'biens_mobiliers', 'delete');

// ════════════════════════════════════════════════════════════════════
//  DÉTECTION DU MODE
//  ?etage=N        → mode Siège  (sidebar bureaux par étage)
//  ?lieu=CODE      → mode Lieu   (agence, centre, aéroport…)
// ════════════════════════════════════════════════════════════════════
$modeSiege = isset($_GET['etage']);
$modeLieu  = isset($_GET['lieu']) && !$modeSiege;

if(!$modeSiege && !$modeLieu){
  header("Location: biens_mobiliers.php"); exit;
}

// ── Config étages ────────────────────────────────────────────────────
$ETAGES = [
  0=>['label'=>'Rez-de-Chaussée','short'=>'RDC', 'color'=>'#6D28D9,#7C3AED'],
  1=>['label'=>'1er Étage',      'short'=>'1er', 'color'=>'#0F2563,#1D4ED8'],
  2=>['label'=>'2ème Étage',     'short'=>'2ème','color'=>'#701A75,#A21CAF'],
  3=>['label'=>'3ème Étage',     'short'=>'3ème','color'=>'#C8102E,#EF4444'],
  4=>['label'=>'4ème Étage',     'short'=>'4ème','color'=>'#0F2563,#1D4ED8'],
  5=>['label'=>'5ème Étage',     'short'=>'5ème','color'=>'#9B0E23,#C8102E'],
];

// ── Info statique des lieux ──────────────────────────────────────────
$LIEUX_INFO = [
  'SIEGE_TUNISAIR'       =>['label'=>'Siège Tunisair',              'icon'=>'🏢','color'=>'#C8102E'],
  'SIEGE_TECHNICS'       =>['label'=>'Siège Technics',              'icon'=>'🔧','color'=>'#0F2563'],
  'SIEGE_AISA'           =>['label'=>'Siège AISA',                  'icon'=>'🏛️','color'=>'#6D28D9'],
  'APT_TUNIS_CARTHAGE'   =>['label'=>'Aéroport Tunis-Carthage',     'icon'=>'✈️','color'=>'#1D4ED8'],
  'APT_MONASTIR'         =>['label'=>'Aéroport Monastir',           'icon'=>'✈️','color'=>'#059669'],
  'APT_SFAX'             =>['label'=>'Aéroport Sfax',               'icon'=>'✈️','color'=>'#D97706'],
  'CENTRE_FORMATION'     =>['label'=>'Centre de Formation',         'icon'=>'📚','color'=>'#7C3AED'],
  'CENTRE_MEDICALE'      =>['label'=>'Centre Médical',              'icon'=>'🏥','color'=>'#DC2626'],
  'FRET'                 =>['label'=>'Fret',                        'icon'=>'📦','color'=>'#92400E'],
  'REPRESENTATION_DJE'   =>['label'=>'Représentation Djerba',       'icon'=>'🏪','color'=>'#065F46'],
  'REPRESENTATION_SOUSSE'=>['label'=>'Représentation Sousse',       'icon'=>'🏪','color'=>'#1E40AF'],
  'DELEGATION_AV_LIBERTE'=>['label'=>'Délégation Gén. Av. Liberté','icon'=>'🏬','color'=>'#701A75'],
  'BTO_SFAX'             =>['label'=>'BTO Sfax',                    'icon'=>'🏬','color'=>'#B45309'],
  'AGENCE_BIZERTE'       =>['label'=>'Agence Bizerte',              'icon'=>'🏬','color'=>'#0369A1'],
  'AGENCE_SOUSSE'        =>['label'=>'Agence Sousse',               'icon'=>'🏬','color'=>'#0284C7'],
  'AGENCE_NABEUL'        =>['label'=>'Agence Nabeul',               'icon'=>'🏬','color'=>'#0369A1'],
  'AGENCE_LA_MARSA'      =>['label'=>'Agence La Marsa',             'icon'=>'🏬','color'=>'#0369A1'],
  'AGENCE_MONASTIR'      =>['label'=>'Agence Monastir',             'icon'=>'🏬','color'=>'#059669'],
  'AGENCE_FIDELYS'       =>['label'=>'Agence Fidelys',              'icon'=>'🏬','color'=>'#6D28D9'],
  'AGENCE_KASBA'         =>['label'=>'Agence Kasba',                'icon'=>'🏬','color'=>'#0369A1'],
  'AGENCE_SIEGE_TUNISAIR'=>['label'=>'Agence Siège Tunisair',       'icon'=>'🏬','color'=>'#C8102E'],
];

// ── Formulaires ──────────────────────────────────────────────────────
$etats = ['FONCTIONNEL','NON FONCTIONNEL','EN REPARATION','MISE EN REBUT'];
$sous_fams = [
  'ARMOIRE','BUREAU GM','BUREAU PM','CANAPÉ','CHAISE ROULANTE','CHAISE VISITEUR',
  'CLIMATISEUR','CLASSEUR','DESTRUCTEUR DE DOC GES','ECRAN','ELEMENT DE RANGEMENT',
  'FAUTEUIL','FAUTEUIL GM','FAUTEUIL ORGONO','FAUTEUIL PM','IMPRIMANTE','LAMPE',
  'MINI BAR','PORTE MANTEAU','REFRIGERATEUR','RETOUR DE BUREAU','TABLE BASE',
  'TABLE DE RÉUNION','TAPIS','TÉLÉPHONE','Autre'
];

// ════════════════════════════════════════════════════════════════════
//  MODE SIÈGE
// ════════════════════════════════════════════════════════════════════
if($modeSiege){
  $etage = intval($_GET['etage']??0);
  if(!isset($ETAGES[$etage])) $etage=0;
  $econf = $ETAGES[$etage];
  $c1 = explode(',',$econf['color'])[0];
  $c2 = explode(',',$econf['color'])[1];
  $accentColor = $c1;
  $pageTitle   = 'Mobilier — '.$econf['label'];
  $backUrl     = 'biens_mobiliers.php?lieu=SIEGE_TUNISAIR';
  $backBc      = '<a href="biens_mobiliers.php?lieu=SIEGE_TUNISAIR">Siège Tunisair</a><span style="opacity:.4">›</span><strong style="color:var(--ink);">'.htmlspecialchars($econf['label']).'</strong>';

  // POST
  if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';
    if($action==='add_mobilier' && !empty($_POST['bureau_id'])){
      requireModulePermission($pdo, 'biens_mobiliers', 'create');
      try{
        $bid=intval($_POST['bureau_id']);
        $br=$pdo->prepare("SELECT ref_bureau,direction FROM siege_bureaux WHERE id=?");
        $br->execute([$bid]); $br=$br->fetch(PDO::FETCH_ASSOC);
        $cbp=$br['ref_bureau']??null;
        $rn=$cbp?strtoupper(preg_replace('/[\s\/\-\.]+/','',$cbp)):null;
        $pdo->prepare("INSERT INTO biens_mobiliers(bureau_id,lieu_physique_id,designation,n_immo,code_article_physique,sous_famille,modele,n_serie,etat_bien,nom_utilisateur,matricule,quantite,valeur,date_acquisition,notes,lieu_physique,site_physique,code_bureau_physique,ref_bureau_physique,direction)VALUES(?,1,?,?,?,?,?,?,?,?,?,?,?,?,?,'SIEGE TUNISAIR','TUNISIE/TUNIS',?,?,?)")
          ->execute([$bid,$_POST['designation']??null,($_POST['n_immo']??null)?:null,$_POST['code_article_physique']??null,$_POST['sous_famille']??null,$_POST['modele']??null,($_POST['n_serie']??null)?:null,$_POST['etat_bien']??'FONCTIONNEL',($_POST['nom_utilisateur']??null)?:null,($_POST['matricule']??null)?:null,intval($_POST['quantite']??1),($_POST['valeur']??null)?:null,($_POST['date_acquisition']??null)?:null,($_POST['notes']??null)?:null,$cbp,$rn,$br['direction']??null]);
      }catch(Exception $e){}
      header("Location: ?etage=$etage&bureau_id=".$_POST['bureau_id']."&add_ok=1"); exit;
    }
    if($action==='edit_mobilier' && !empty($_POST['id'])){
      requireModulePermission($pdo, 'biens_mobiliers', 'update');
      try{$pdo->prepare("UPDATE biens_mobiliers SET designation=?,n_immo=?,code_article_physique=?,sous_famille=?,modele=?,n_serie=?,etat_bien=?,nom_utilisateur=?,matricule=?,quantite=?,valeur=?,date_acquisition=?,notes=? WHERE id=?")->execute([$_POST['designation']??null,($_POST['n_immo']??null)?:null,$_POST['code_article_physique']??null,$_POST['sous_famille']??null,$_POST['modele']??null,($_POST['n_serie']??null)?:null,$_POST['etat_bien']??'FONCTIONNEL',($_POST['nom_utilisateur']??null)?:null,($_POST['matricule']??null)?:null,intval($_POST['quantite']??1),($_POST['valeur']??null)?:null,($_POST['date_acquisition']??null)?:null,($_POST['notes']??null)?:null,intval($_POST['id'])]);}catch(Exception $e){}
      header("Location: ?etage=$etage&bureau_id=".($_POST['bureau_id']??'')."&edit_ok=1"); exit;
    }
    if($action==='delete_mobilier' && !empty($_POST['id'])){
      requireModulePermission($pdo, 'biens_mobiliers', 'delete');
      try{$pdo->prepare("DELETE FROM biens_mobiliers WHERE id=?")->execute([intval($_POST['id'])]);}catch(Exception $e){}
      header("Location: ?etage=$etage&bureau_id=".($_POST['bureau_id']??'')); exit;
    }
    header("Location: ?etage=$etage"); exit;
  }

  // Bureaux
  $bureaux=[];
  try{$s=$pdo->prepare("SELECT id,etage,ref_bureau,COALESCE(surf_m2,superficie) AS superficie,statut,direction,TRIM(CONCAT(COALESCE(nom,''),' ',COALESCE(prenom,''))) AS occupant,l_fonct FROM siege_bureaux WHERE etage=? ORDER BY ref_bureau ASC");$s->execute([$etage]);$bureaux=$s->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){}

  // Comptages
  $counts=[];
  try{
    foreach($pdo->query("SELECT bureau_id,COUNT(*) nb FROM biens_mobiliers WHERE bureau_id IS NOT NULL GROUP BY bureau_id")->fetchAll() as $r) $counts[intval($r['bureau_id'])]=intval($r['nb']);
    foreach($bureaux as $b){
      $bid=intval($b['id']);
      // Normalisation robuste : supprime espaces/slashes/tirets/points ET remplace O(lettre)↔0(chiffre)
      $rn=str_replace('0','O',strtoupper(preg_replace('/[\s\/\-\.]+/','',$b['ref_bureau'])));
      $s2=$pdo->prepare("
        SELECT COUNT(*) FROM biens_mobiliers
        WHERE bureau_id IS NULL
          AND code_bureau_physique IS NOT NULL
          AND REPLACE(UPPER(REPLACE(REPLACE(REPLACE(code_bureau_physique,'/',''),'-',''),' ','')), '0', 'O') = ?
      ");
      $s2->execute([$rn]); $n2=intval($s2->fetchColumn());
      if($n2>0) $counts[$bid]=($counts[$bid]??0)+$n2;
    }
  }catch(Exception $e){}

  $total_bureaux=count($bureaux); $bureaux_avec_mobilier=0; $total_articles=0;
  foreach($bureaux as $b){$nb=$counts[intval($b['id'])]??0;$total_articles+=$nb;if($nb>0)$bureaux_avec_mobilier++;}

  // Bureau sélectionné
  $selectedBureauId=intval($_GET['bureau_id']??0);
  $selectedBureau=null; $mobiliers=[];
  if($selectedBureauId){
    foreach($bureaux as $b){if(intval($b['id'])===$selectedBureauId){$selectedBureau=$b;break;}}
    if($selectedBureau){
      try{
        // Normalisation robuste : supprime séparateurs ET traite O(lettre)↔0(chiffre) comme équivalents
        $rn=str_replace('0','O',strtoupper(preg_replace('/[\s\/\-\.]+/','',$selectedBureau['ref_bureau'])));
        $st=$pdo->prepare(
          "SELECT * FROM biens_mobiliers WHERE bureau_id=:bid
           UNION
           SELECT m.* FROM biens_mobiliers m
           WHERE m.bureau_id IS NULL
             AND m.code_bureau_physique IS NOT NULL
             AND REPLACE(UPPER(REPLACE(REPLACE(REPLACE(m.code_bureau_physique,'/',''),'-',''),' ','')), '0', 'O') = :ref
           ORDER BY designation ASC"
        );
        $st->execute([':bid'=>$selectedBureauId,':ref'=>$rn]);
        $mobiliers=$st->fetchAll(PDO::FETCH_ASSOC);
      }catch(Exception $e){}
    }
  }

// ════════════════════════════════════════════════════════════════════
//  MODE LIEU GÉNÉRIQUE
// ════════════════════════════════════════════════════════════════════
} else {
  $codeLieu  = $_GET['lieu'];
  $lieuInfo  = $LIEUX_INFO[$codeLieu] ?? null;

  // Si le code n'est pas dans la liste statique, on essaie de le récupérer depuis la DB
  if(!$lieuInfo){
    try{
      $row=$pdo->prepare("SELECT label,icon,color FROM lieux_physiques WHERE code=? AND actif=1 LIMIT 1");
      $row->execute([$codeLieu]); $ld=$row->fetch(PDO::FETCH_ASSOC);
      if($ld) $lieuInfo=['label'=>$ld['label'],'icon'=>$ld['icon']??'📍','color'=>$ld['color']??'#C8102E'];
    }catch(Exception $e){}
    if(!$lieuInfo) $lieuInfo=['label'=>$codeLieu,'icon'=>'📍','color'=>'#C8102E'];
  }

  $accentColor = $lieuInfo['color'];
  $c1=$accentColor; $c2=$accentColor;
  $pageTitle  = 'Mobilier — '.$lieuInfo['label'];
  $backUrl    = 'biens_mobiliers.php';
  $backBc     = '<strong style="color:var(--ink);">'.htmlspecialchars($lieuInfo['label']).'</strong>';
  $econf      = ['label'=>$lieuInfo['label'],'short'=>$lieuInfo['icon']??'📍','color'=>$c1.','.$c2];

  // Récupérer lieu_physique_id
  $lieuPhysiqueId=null;
  try{$r=$pdo->prepare("SELECT id FROM lieux_physiques WHERE code=? AND actif=1 LIMIT 1");$r->execute([$codeLieu]);$lieuPhysiqueId=$r->fetchColumn()?:null;}catch(Exception $e){}

  // POST
  if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';
    if($action==='add_mobilier_lieu'){
      requireModulePermission($pdo, 'biens_mobiliers', 'create');
      try{
        $pdo->prepare("INSERT INTO biens_mobiliers(lieu_physique_id,lieu_physique,designation,n_immo,code_article_physique,sous_famille,modele,n_serie,etat_bien,nom_utilisateur,matricule,quantite,valeur,date_acquisition,notes)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([$lieuPhysiqueId,$lieuInfo['label'],$_POST['designation']??null,($_POST['n_immo']??null)?:null,$_POST['code_article_physique']??null,$_POST['sous_famille']??null,$_POST['modele']??null,($_POST['n_serie']??null)?:null,$_POST['etat_bien']??'FONCTIONNEL',($_POST['nom_utilisateur']??null)?:null,($_POST['matricule']??null)?:null,intval($_POST['quantite']??1),($_POST['valeur']??null)?:null,($_POST['date_acquisition']??null)?:null,($_POST['notes']??null)?:null]);
      }catch(Exception $e){}
      header("Location: ?lieu=".urlencode($codeLieu)."&add_ok=1"); exit;
    }
    if($action==='edit_mobilier' && !empty($_POST['id'])){
      requireModulePermission($pdo, 'biens_mobiliers', 'update');
      try{$pdo->prepare("UPDATE biens_mobiliers SET designation=?,n_immo=?,code_article_physique=?,sous_famille=?,modele=?,n_serie=?,etat_bien=?,nom_utilisateur=?,matricule=?,quantite=?,valeur=?,date_acquisition=?,notes=? WHERE id=?")->execute([$_POST['designation']??null,($_POST['n_immo']??null)?:null,$_POST['code_article_physique']??null,$_POST['sous_famille']??null,$_POST['modele']??null,($_POST['n_serie']??null)?:null,$_POST['etat_bien']??'FONCTIONNEL',($_POST['nom_utilisateur']??null)?:null,($_POST['matricule']??null)?:null,intval($_POST['quantite']??1),($_POST['valeur']??null)?:null,($_POST['date_acquisition']??null)?:null,($_POST['notes']??null)?:null,intval($_POST['id'])]);}catch(Exception $e){}
      header("Location: ?lieu=".urlencode($codeLieu)."&edit_ok=1"); exit;
    }
    if($action==='delete_mobilier' && !empty($_POST['id'])){
      requireModulePermission($pdo, 'biens_mobiliers', 'delete');
      try{$pdo->prepare("DELETE FROM biens_mobiliers WHERE id=?")->execute([intval($_POST['id'])]);}catch(Exception $e){}
      header("Location: ?lieu=".urlencode($codeLieu)); exit;
    }
    header("Location: ?lieu=".urlencode($codeLieu)); exit;
  }

  // Récupérer articles du lieu
  // Cherche par lieu_physique_id (FK) ET par texte lieu_physique (données sans FK)
  $mobiliers=[];
  try{
    $params=[]; $where=[];
    if($lieuPhysiqueId){ $where[]="m.lieu_physique_id=?"; $params[]=$lieuPhysiqueId; }
    $where[]="m.lieu_physique LIKE ?"; $params[]='%'.$lieuInfo['label'].'%';
    // Exclure les articles Siège qui ont un bureau_id (déjà gérés par le mode Siège)
    $sql="SELECT m.* FROM biens_mobiliers m WHERE (".implode(" OR ",$where).") AND m.bureau_id IS NULL ORDER BY m.designation ASC";
    $st=$pdo->prepare($sql); $st->execute($params);
    $mobiliers=$st->fetchAll(PDO::FETCH_ASSOC);
  }catch(Exception $e){}

  $total_articles=count($mobiliers);
  $bureaux=[]; $counts=[]; $total_bureaux=0; $bureaux_avec_mobilier=0;
  $selectedBureauId=0; $selectedBureau=null;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?=htmlspecialchars($pageTitle)?> · TUNISAIR</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --red:#C8102E;--red-dark:#9B0E23;--navy:#0F2563;
  --ink:#1A1A18;--muted:#6B7280;
  --bg:#F4F6F9;--white:#fff;
  --rule:rgba(0,0,0,.07);--shadow:0 4px 20px rgba(0,0,0,.07);
  --ac:<?=$accentColor?>;--ac-glow:<?=$accentColor?>28;
  --sidebar-w:280px;
}
html,body{height:100%;font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--ink);overflow:hidden;}
::-webkit-scrollbar{width:4px;}::-webkit-scrollbar-thumb{background:#D1D5DB;border-radius:2px;}
.navbar{background:var(--white);border-bottom:3px solid var(--red);box-shadow:0 2px 10px rgba(0,0,0,.06);height:64px;padding:0 24px;display:flex;align-items:center;justify-content:space-between;position:fixed;top:0;left:0;right:0;z-index:200;}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.nav-logo{height:36px;object-fit:contain;}
.nav-brand-text{font-size:14px;font-weight:700;color:var(--red);}
.nav-bc{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);}
.nav-bc a{color:var(--muted);text-decoration:none;}.nav-bc a:hover{color:var(--red);}
.nav-right{display:flex;align-items:center;gap:14px;}
.nav-user{font-size:13px;font-weight:500;color:var(--muted);}
.btn-logout{background:var(--red);color:white;padding:7px 16px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:600;}
.app{display:flex;height:calc(100vh - 64px);margin-top:64px;}

/* ── SIDEBAR ── */
.sidebar{width:var(--sidebar-w);flex-shrink:0;background:var(--white);border-right:1.5px solid var(--rule);display:flex;flex-direction:column;overflow:hidden;}
.sb-top{padding:13px 12px;border-bottom:1.5px solid var(--rule);flex-shrink:0;}
.btn-back{display:flex;align-items:center;gap:7px;padding:8px 11px;border-radius:8px;border:1.5px solid var(--rule);background:var(--bg);color:var(--muted);text-decoration:none;font-size:12px;font-weight:600;width:100%;margin-bottom:8px;transition:all .18s;cursor:pointer;}
.btn-back:hover{border-color:var(--ac);color:var(--ac);}
.etage-tag{display:flex;align-items:center;gap:9px;padding:10px 13px;border-radius:11px;background:linear-gradient(135deg,<?=$c1?>,<?=$c2?>);margin-bottom:8px;}
.et-badge{width:36px;height:36px;border-radius:9px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:white;flex-shrink:0;}
.et-info{flex:1;}.et-name{font-size:13px;font-weight:700;color:white;}.et-sub{font-size:10px;color:rgba(255,255,255,.7);margin-top:1px;}
.sb-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:5px;margin-bottom:8px;}
.ss{background:var(--bg);border-radius:8px;padding:7px;text-align:center;border:1.5px solid var(--rule);}
.ss-v{font-size:14px;font-weight:700;color:var(--ink);}.ss-l{font-size:9px;color:var(--muted);margin-top:1px;}
.sb-search{position:relative;}
.sb-search svg{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none;}
.sb-search input{width:100%;padding:8px 10px 8px 30px;border:1.5px solid var(--rule);border-radius:9px;font-size:12px;font-family:'DM Sans',sans-serif;color:var(--ink);background:var(--bg);outline:none;transition:border-color .2s;}
.sb-search input:focus{border-color:var(--ac);}
.sb-label{padding:9px 14px 5px;font-size:9.5px;font-weight:700;letter-spacing:.13em;text-transform:uppercase;color:var(--muted);}
.sb-list{flex:1;overflow-y:auto;padding:5px 9px 12px;}
.bureau-item{display:flex;align-items:center;gap:9px;padding:9px 10px;border-radius:10px;cursor:pointer;transition:background .14s;margin-bottom:3px;}
.bureau-item:hover{background:var(--bg);}
.bureau-item.active{background:linear-gradient(135deg,<?=$c1?>,<?=$c2?>);box-shadow:0 3px 12px var(--ac-glow);}
.bi-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;background:#D1D5DB;}
.bureau-item.active .bi-dot{background:rgba(255,255,255,.5);}
.bi-body{flex:1;min-width:0;}
.bi-ref{font-size:12px;font-weight:700;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.bureau-item.active .bi-ref{color:white;}
.bi-meta{font-size:10px;color:var(--muted);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.bureau-item.active .bi-meta{color:rgba(255,255,255,.7);}
.bi-badge{flex-shrink:0;font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px;background:var(--ac-glow);color:var(--ac);}
.bureau-item.active .bi-badge{background:rgba(255,255,255,.25);color:white;}
.bi-badge.zero{background:#F3F4F6;color:#9CA3AF;}

/* ── MAIN ── */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;}
.topbar{background:var(--white);border-bottom:1.5px solid var(--rule);padding:14px 22px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;gap:16px;}
.topbar-info{flex:1;}
.topbar-title{font-size:17px;font-weight:700;color:var(--navy);}
.topbar-sub{font-size:12px;color:var(--muted);margin-top:2px;}
.btn-add-mob{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:10px;background:linear-gradient(135deg,<?=$c1?>,<?=$c2?>);color:white;border:none;cursor:pointer;font-size:13px;font-weight:600;font-family:'DM Sans',sans-serif;box-shadow:0 3px 12px var(--ac-glow);transition:opacity .18s,transform .15s;flex-shrink:0;}
.btn-add-mob:hover{opacity:.88;transform:translateY(-1px);}
.btn-add-mob:disabled{opacity:.5;cursor:not-allowed;transform:none;}
.content{flex:1;overflow-y:auto;padding:22px;}
.empty-main{height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;text-align:center;color:var(--muted);}
.empty-icon-w{width:80px;height:80px;border-radius:20px;background:var(--white);display:flex;align-items:center;justify-content:center;border:2px dashed #D1D5DB;font-size:36px;}
.empty-main h3{font-size:17px;font-weight:700;color:var(--ink);margin-bottom:4px;}
.empty-main p{font-size:13px;max-width:300px;line-height:1.6;}
.bureau-card{background:var(--white);border-radius:14px;border:1.5px solid var(--rule);padding:18px 20px;margin-bottom:16px;box-shadow:var(--shadow);}
.bc-header{display:flex;align-items:center;gap:14px;}
.bc-avatar{width:48px;height:48px;border-radius:13px;background:linear-gradient(135deg,<?=$c1?>,<?=$c2?>);display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;color:white;flex-shrink:0;}
.bc-info{flex:1;}
.bc-ref{font-size:16px;font-weight:700;color:var(--navy);}
.bc-code{font-size:11px;font-family:monospace;font-weight:700;color:var(--ac);background:var(--ac-glow);padding:2px 8px;border-radius:5px;display:inline-block;margin-top:3px;}
.bc-dir{font-size:12px;color:var(--muted);margin-top:3px;}
.bc-badges{display:flex;align-items:center;gap:7px;margin-top:10px;flex-wrap:wrap;}
.abdg{font-size:10px;font-weight:600;padding:3px 10px;border-radius:20px;border:1px solid var(--rule);background:var(--bg);color:var(--muted);}
.mob-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:18px;}
.ms{background:var(--white);border-radius:11px;border:1.5px solid var(--rule);padding:12px 14px;border-left:3px solid var(--ac);}
.ms.green{border-left-color:#059669;}.ms.orange{border-left-color:#D97706;}.ms.red{border-left-color:#DC2626;}
.ms-v{font-size:20px;font-weight:700;color:var(--ink);}
.ms-l{font-size:9.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-top:2px;}
.table-wrap{background:var(--white);border-radius:14px;border:1.5px solid var(--rule);overflow:hidden;box-shadow:var(--shadow);}
.table-header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1.5px solid var(--rule);flex-wrap:wrap;gap:10px;}
.table-title{font-size:10px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);}
.table-search{position:relative;}
.table-search svg{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none;}
.table-search input{padding:7px 10px 7px 28px;border:1.5px solid var(--rule);border-radius:8px;font-size:12px;font-family:'DM Sans',sans-serif;color:var(--ink);background:var(--bg);outline:none;width:180px;transition:border-color .2s;}
.table-search input:focus{border-color:var(--ac);}
.mob-table{width:100%;border-collapse:collapse;}
.mob-table th{padding:10px 14px;font-size:9.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);text-align:left;background:#FAFAFA;border-bottom:1.5px solid var(--rule);}
.mob-table td{padding:11px 14px;font-size:13px;color:var(--ink);border-bottom:1px solid var(--rule);vertical-align:middle;}
.mob-table tr:last-child td{border-bottom:none;}
.mob-table tr:hover td{background:#FAFBFC;}
.code-cell{font-family:monospace;font-size:11px;font-weight:700;color:var(--ac);background:var(--ac-glow);padding:2px 7px;border-radius:5px;white-space:nowrap;}
.etat-badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:10.5px;font-weight:700;}
.eb-fonc{background:#DCFCE7;color:#15803D;}.eb-nfonc{background:#FEE2E2;color:#DC2626;}.eb-rep{background:#DBEAFE;color:#1E40AF;}.eb-reb{background:#F3F4F6;color:#4B5563;}.eb-other{background:#FEF3C7;color:#92400E;}
.action-btns{display:flex;gap:6px;}
.abtn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;border:1.5px solid var(--rule);background:var(--bg);color:var(--muted);cursor:pointer;transition:all .14s;font-family:'DM Sans',sans-serif;}
.abtn:hover{background:var(--white);border-color:var(--ac);color:var(--ac);}
.abtn-del:hover{border-color:#DC2626;color:#DC2626;}
.qty-chip{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:8px;background:var(--ac-glow);color:var(--ac);font-size:12px;font-weight:700;}
.no-rows{padding:40px;text-align:center;color:var(--muted);font-size:13px;}

/* ── LIEU GÉNÉRIQUE : en-tête pleine largeur ── */
.lieu-hero{background:linear-gradient(135deg,<?=$c1?>,<?=$c2?>);border-radius:14px;padding:22px 24px;margin-bottom:16px;display:flex;align-items:center;gap:16px;box-shadow:0 6px 20px var(--ac-glow);}
.lieu-hero-icon{font-size:36px;}
.lieu-hero-info{flex:1;}
.lieu-hero-name{font-size:20px;font-weight:700;color:white;}
.lieu-hero-sub{font-size:12px;color:rgba(255,255,255,.75);margin-top:3px;}
.lieu-hero-stats{display:flex;gap:12px;}
.lhs{text-align:center;padding:8px 14px;border-radius:10px;background:rgba(255,255,255,.15);}
.lhs-v{font-size:18px;font-weight:700;color:white;}
.lhs-l{font-size:9px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.7);margin-top:2px;}

/* ── MODALS ── */
.modal-bg{display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.52);backdrop-filter:blur(5px);align-items:center;justify-content:center;padding:22px;}
.modal-bg.open{display:flex;}
.modal-inner{background:var(--bg);border-radius:18px;width:min(680px,95vw);max-height:90vh;overflow-y:auto;padding:28px 30px;box-shadow:0 32px 80px rgba(0,0,0,.2);}
.modal-inner h2{font-size:13px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--ink);margin-bottom:4px;}
.modal-sub{font-size:12px;color:var(--muted);margin-bottom:20px;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.fg{display:flex;flex-direction:column;gap:5px;}.fg.full{grid-column:1/-1;}
.form-label{font-size:9.5px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);}
.form-input{padding:9px 13px;border-radius:9px;border:1.5px solid var(--rule);background:white;font-family:'DM Sans',sans-serif;font-size:13px;color:var(--ink);outline:none;transition:border-color .2s;width:100%;}
.form-input:focus{border-color:var(--ac);}
.form-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;border-top:1px solid var(--rule);padding-top:16px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 17px;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;border:none;font-family:'DM Sans',sans-serif;transition:all .15s;}
.btn:hover{transform:translateY(-1px);}
.btn-primary{background:linear-gradient(135deg,<?=$c1?>,<?=$c2?>);color:white;box-shadow:0 4px 14px var(--ac-glow);}
.btn-ghost{background:var(--white);color:var(--ink);border:1.5px solid var(--rule);}
.btn-ghost:hover{background:var(--bg);}
.btn-danger{background:#FEF2F2;color:#DC2626;border:1.5px solid #FECACA;}
.del-inner{background:white;border-radius:16px;padding:30px;width:min(380px,92vw);text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.18);}
.del-inner h3{font-size:13px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;margin-bottom:10px;}
.del-inner p{font-size:13px;color:var(--muted);margin-bottom:20px;line-height:1.6;}
.del-actions{display:flex;gap:9px;justify-content:center;}
.toast{position:fixed;top:74px;right:22px;z-index:900;border-radius:12px;padding:11px 18px;font-size:12.5px;font-weight:600;display:flex;align-items:center;gap:9px;box-shadow:0 6px 24px rgba(0,0,0,.12);animation:toastIn .3s ease;}
.toast.ok{background:#ECFDF5;color:#065F46;border:1.5px solid #A7F3D0;}
@keyframes toastIn{from{opacity:0;transform:translateX(20px);}to{opacity:1;transform:none;}}
.anim{animation:slideIn .25s ease;}@keyframes slideIn{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:none;}}
</style>
</head>
<body>

<?php if(isset($_GET['add_ok'])): ?>
<div class="toast ok" id="toast"><svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="#059669" stroke-width="1.4"/><path d="M5 8l2.5 2.5L11 5.5" stroke="#059669" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>Article ajouté !</div>
<script>setTimeout(()=>document.getElementById('toast')?.remove(),3000);</script>
<?php endif; ?>
<?php if(isset($_GET['edit_ok'])): ?>
<div class="toast ok" id="toast"><svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="#059669" stroke-width="1.4"/><path d="M5 8l2.5 2.5L11 5.5" stroke="#059669" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>Modifications enregistrées !</div>
<script>setTimeout(()=>document.getElementById('toast')?.remove(),3000);</script>
<?php endif; ?>

<nav class="navbar">
  <div style="display:flex;align-items:center;gap:14px;">
    <a href="index.php" class="nav-brand">
      <img src="logo.webp" alt="TUNISAIR" class="nav-logo">
      <span class="nav-brand-text">TUNISAIR — Patrimoine</span>
    </a>
    <span style="opacity:.3;font-size:18px;">|</span>
    <nav class="nav-bc">
      <a href="dashboard.php">Accueil</a><span style="opacity:.4">›</span>
      <a href="biens_mobiliers.php">Biens Mobiliers</a><span style="opacity:.4">›</span>
      <?=$backBc?>
    </nav>
  </div>
  <div class="nav-right">
    <span class="nav-user"><?=htmlspecialchars($username)?></span>
    <a href="logout.php" class="btn-logout">Déconnexion</a>
  </div>
</nav>

<div class="app">

<?php /* ══════════════ MODE SIÈGE ══════════════ */
if($modeSiege): ?>

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sb-top">
    <a href="<?=htmlspecialchars($backUrl)?>" class="btn-back">
      <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Retour aux étages
    </a>
    <div class="etage-tag">
      <div class="et-badge"><?=htmlspecialchars($econf['short'])?></div>
      <div class="et-info">
        <div class="et-name"><?=htmlspecialchars($econf['label'])?></div>
        <div class="et-sub"><?=$total_bureaux?> bureaux · <?=$total_articles?> articles</div>
      </div>
    </div>
    <div class="sb-stats">
      <div class="ss"><div class="ss-v"><?=$total_bureaux?></div><div class="ss-l">Bureaux</div></div>
      <div class="ss"><div class="ss-v" style="color:var(--ac)"><?=$bureaux_avec_mobilier?></div><div class="ss-l">Équipés</div></div>
      <div class="ss"><div class="ss-v"><?=$total_articles?></div><div class="ss-l">Articles</div></div>
    </div>
    <div class="sb-search">
      <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><circle cx="6.5" cy="6.5" r="4.5" stroke="currentColor" stroke-width="1.4"/><path d="M10 10l3.5 3.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
      <input type="text" placeholder="Rechercher un bureau…" oninput="filterBureaux(this.value)">
    </div>
  </div>
  <div class="sb-label">BUREAUX (<?=$total_bureaux?>)</div>
  <div class="sb-list">
    <?php foreach($bureaux as $b):
      $nb=$counts[intval($b['id'])]??0; $ref=$b['ref_bureau']??'—';
      $dir=$b['direction']??''; $occ=$b['occupant']??'';
    ?>
    <div class="bureau-item <?=intval($b['id'])===$selectedBureauId?'active':''?>"
         data-search="<?=htmlspecialchars(strtolower($ref.' '.$dir.' '.$occ))?>"
         onclick="window.location='?etage=<?=$etage?>&bureau_id=<?=$b['id']?>'">
      <div class="bi-dot"></div>
      <div class="bi-body">
        <div class="bi-ref"><?=htmlspecialchars($ref)?></div>
        <div class="bi-meta"><?=$dir?htmlspecialchars(substr($dir,0,30)):($occ?htmlspecialchars(substr($occ,0,30)):'Bureau libre')?></div>
      </div>
      <span class="bi-badge <?=$nb===0?'zero':''?>"><?=$nb?></span>
    </div>
    <?php endforeach; ?>
  </div>
</aside>

<!-- MAIN SIÈGE -->
<div class="main">
  <div class="topbar">
    <div class="topbar-info">
      <?php if($selectedBureau): ?>
        <div class="topbar-title">Bureau <?=htmlspecialchars($selectedBureau['ref_bureau']??'—')?></div>
        <div class="topbar-sub"><?=count($mobiliers)?> article<?=count($mobiliers)>1?'s':''?> enregistré<?=count($mobiliers)>1?'s':''?></div>
      <?php else: ?>
        <div class="topbar-title">Sélectionnez un bureau</div>
        <div class="topbar-sub"><?=htmlspecialchars($econf['label'])?> — <?=$total_bureaux?> bureaux disponibles</div>
      <?php endif; ?>
    </div>
    <?php if($canCreate): ?>
    <button class="btn-add-mob" onclick="openAddModal()" <?=$selectedBureau?'':'disabled'?>>
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M2 8h12" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
      Ajouter un article
    </button>
    <?php endif; ?>
  </div>
  <div class="content">
    <?php if(!$selectedBureau): ?>
    <div class="empty-main">
      <div class="empty-icon-w">
        <svg width="34" height="34" viewBox="0 0 24 24" fill="none"><rect x="2" y="7" width="20" height="13" rx="2" stroke="#D1D5DB" stroke-width="1.5"/><path d="M2 11h20M8 7V5a2 2 0 012-2h4a2 2 0 012 2v2" stroke="#D1D5DB" stroke-width="1.5" stroke-linecap="round"/></svg>
      </div>
      <h3>Sélectionnez un bureau</h3>
      <p>Choisissez un bureau dans la liste à gauche pour consulter et gérer son mobilier.</p>
    </div>
    <?php else:
      $nb_fonc =count(array_filter($mobiliers,fn($m)=>stripos($m['etat_bien']??'','FONCTIONNEL')!==false&&stripos($m['etat_bien']??'','NON')===false));
      $nb_nfonc=count(array_filter($mobiliers,fn($m)=>stripos($m['etat_bien']??'','NON FONCTIONNEL')!==false||stripos($m['etat_bien']??'','REBUT')!==false));
      $nb_rep  =count(array_filter($mobiliers,fn($m)=>stripos($m['etat_bien']??'','REPARATION')!==false));
      $val_total=array_sum(array_column($mobiliers,'valeur'));
    ?>
    <div class="anim">
      <div class="bureau-card">
        <div class="bc-header">
          <div class="bc-avatar"><?=htmlspecialchars(substr($selectedBureau['ref_bureau']??'?',0,3))?></div>
          <div class="bc-info">
            <div class="bc-ref">Bureau <?=htmlspecialchars($selectedBureau['ref_bureau']??'—')?></div>
            <div class="bc-code"><?=htmlspecialchars(strtoupper(preg_replace('/[\s\/\-\.]+/','',$selectedBureau['ref_bureau']??'')))?></div>
            <?php if(!empty($selectedBureau['direction'])): ?><div class="bc-dir"><?=htmlspecialchars($selectedBureau['direction'])?></div><?php endif; ?>
          </div>
          <div style="text-align:right;flex-shrink:0">
            <div style="font-size:22px;font-weight:700;color:var(--ac)"><?=count($mobiliers)?></div>
            <div style="font-size:10px;color:var(--muted);font-weight:600">articles</div>
          </div>
        </div>
        <div class="bc-badges">
          <span class="abdg">📍 <?=htmlspecialchars($econf['label'])?></span>
          <?php if(!empty($selectedBureau['superficie'])): ?><span class="abdg">📐 <?=number_format(floatval($selectedBureau['superficie']),1)?> m²</span><?php endif; ?>
          <?php if(!empty($selectedBureau['statut'])): ?><span class="abdg">🔖 <?=htmlspecialchars($selectedBureau['statut'])?></span><?php endif; ?>
          <?php if(!empty(trim($selectedBureau['occupant']??''))): ?><span class="abdg">👤 <?=htmlspecialchars(trim($selectedBureau['occupant']))?></span><?php endif; ?>
          <?php if(!empty($selectedBureau['l_fonct'])): ?><span class="abdg">💼 <?=htmlspecialchars($selectedBureau['l_fonct'])?></span><?php endif; ?>
        </div>
      </div>
      <div class="mob-stats">
        <div class="ms"><div class="ms-v"><?=count($mobiliers)?></div><div class="ms-l">Total articles</div></div>
        <div class="ms green"><div class="ms-v"><?=$nb_fonc?></div><div class="ms-l">Fonctionnel</div></div>
        <div class="ms orange"><div class="ms-v"><?=$nb_rep?></div><div class="ms-l">En réparation</div></div>
        <div class="ms red"><div class="ms-v"><?=$nb_nfonc?></div><div class="ms-l">Non fonctionnel</div></div>
      </div>
      <?php renderMobilierTable($mobiliers,$val_total,$canUpdate,$canDelete); ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php /* ══════════════ MODE LIEU GÉNÉRIQUE ══════════════ */
else:
  $nb_fonc =count(array_filter($mobiliers,fn($m)=>stripos($m['etat_bien']??'','FONCTIONNEL')!==false&&stripos($m['etat_bien']??'','NON')===false));
  $nb_nfonc=count(array_filter($mobiliers,fn($m)=>stripos($m['etat_bien']??'','NON FONCTIONNEL')!==false||stripos($m['etat_bien']??'','REBUT')!==false));
  $nb_rep  =count(array_filter($mobiliers,fn($m)=>stripos($m['etat_bien']??'','REPARATION')!==false));
  $val_total=array_sum(array_column($mobiliers,'valeur'));
?>

<!-- PAS DE SIDEBAR — layout pleine largeur -->
<div class="main">
  <div class="topbar">
    <div class="topbar-info">
      <div class="topbar-title"><?=htmlspecialchars($lieuInfo['icon']??'📍')?> <?=htmlspecialchars($lieuInfo['label'])?></div>
      <div class="topbar-sub"><?=$total_articles?> article<?=$total_articles>1?'s':''?> enregistré<?=$total_articles>1?'s':''?></div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
      <a href="<?=htmlspecialchars($backUrl)?>" class="btn-back" style="width:auto;margin-bottom:0;text-decoration:none;">
        <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Retour
      </a>
      <?php if($canCreate): ?>
      <button class="btn-add-mob" onclick="openAddModal()">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M2 8h12" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
        Ajouter un article
      </button>
      <?php endif; ?>
    </div>
  </div>
  <div class="content">
    <div class="anim">
      <!-- EN-TÊTE LIEU -->
      <div class="lieu-hero">
        <div class="lieu-hero-icon"><?=htmlspecialchars($lieuInfo['icon']??'📍')?></div>
        <div class="lieu-hero-info">
          <div class="lieu-hero-name"><?=htmlspecialchars($lieuInfo['label'])?></div>
          <div class="lieu-hero-sub"><?=$total_articles?> article<?=$total_articles>1?'s':''?> au total</div>
        </div>
        <div class="lieu-hero-stats">
          <div class="lhs"><div class="lhs-v"><?=$total_articles?></div><div class="lhs-l">Total</div></div>
          <div class="lhs"><div class="lhs-v"><?=$nb_fonc?></div><div class="lhs-l">Fonct.</div></div>
          <div class="lhs"><div class="lhs-v"><?=$nb_rep?></div><div class="lhs-l">Réparat.</div></div>
          <div class="lhs"><div class="lhs-v"><?=$nb_nfonc?></div><div class="lhs-l">N/Fonct.</div></div>
        </div>
      </div>

      <?php if(empty($mobiliers)): ?>
      <div class="empty-main" style="height:45vh">
        <div class="empty-icon-w"><?=htmlspecialchars($lieuInfo['icon']??'📍')?></div>
        <h3>Aucun article pour ce lieu</h3>
        <p>Cliquez sur "Ajouter un article" pour enregistrer le premier article de <strong><?=htmlspecialchars($lieuInfo['label'])?></strong>.</p>
      </div>
      <?php else: ?>
      <?php renderMobilierTable($mobiliers,$val_total,$canUpdate,$canDelete); ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

</div><!-- /app -->

<!-- ══ ADD MODAL ══ -->
<div class="modal-bg" id="addModal">
  <div class="modal-inner">
    <h2>Ajouter un article de mobilier</h2>
    <p class="modal-sub">
      <?php if($modeSiege && $selectedBureau): ?>
        Bureau : <strong><?=htmlspecialchars($selectedBureau['ref_bureau']??'—')?></strong>
        <?php if(!empty($selectedBureau['direction'])): ?> — <?=htmlspecialchars(substr($selectedBureau['direction'],0,50))?><?php endif; ?>
      <?php else: ?>
        Lieu : <strong><?=htmlspecialchars($lieuInfo['label']??'')?></strong>
      <?php endif; ?>
    </p>
    <form method="post">
      <!-- FIX CLÉ : action différente selon le mode -->
      <input type="hidden" name="action" value="<?=$modeSiege?'add_mobilier':'add_mobilier_lieu'?>">
      <?php if($modeSiege): ?>
      <input type="hidden" name="bureau_id" value="<?=$selectedBureauId?>">
      <?php endif; ?>
      <div class="form-grid">
        <div class="fg full">
          <label class="form-label">Désignation *</label>
          <select class="form-input" name="designation" required id="selDesig">
            <option value="">— Sélectionner —</option>
            <?php foreach($sous_fams as $sf): ?><option value="<?=htmlspecialchars($sf)?>"><?=htmlspecialchars($sf)?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="fg full" id="autreWrap" style="display:none;">
          <label class="form-label">Désignation personnalisée</label>
          <input class="form-input" type="text" name="designation_autre" placeholder="Ex: Table basse, Étagère…">
        </div>
        <div class="fg"><label class="form-label">N° Immo</label><input class="form-input" type="text" name="n_immo" placeholder="Ex: REF 19TUN-MB12900"></div>
        <div class="fg"><label class="form-label">Code article physique</label><input class="form-input" type="text" name="code_article_physique" placeholder="Ex: MB-001"></div>
        <div class="fg"><label class="form-label">Sous-famille</label><input class="form-input" type="text" name="sous_famille" placeholder="Ex: ARMOIRE"></div>
        <div class="fg"><label class="form-label">Modèle</label><input class="form-input" type="text" name="modele" placeholder="Ex: ARMOIRE 2 portes beige"></div>
        <div class="fg"><label class="form-label">N° Série</label><input class="form-input" type="text" name="n_serie" placeholder="Ex: F0200732X5680"></div>
        <div class="fg"><label class="form-label">Quantité</label><input class="form-input" type="number" name="quantite" value="1" min="1"></div>
        <div class="fg"><label class="form-label">État</label>
          <select class="form-input" name="etat_bien">
            <?php foreach($etats as $et): ?><option><?=htmlspecialchars($et)?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="fg"><label class="form-label">Nom utilisateur</label><input class="form-input" type="text" name="nom_utilisateur" placeholder="Ex: NIZAR"></div>
        <div class="fg"><label class="form-label">Matricule</label><input class="form-input" type="text" name="matricule" placeholder="Ex: 12345"></div>
        <div class="fg"><label class="form-label">Valeur (TND)</label><input class="form-input" type="number" step="0.01" name="valeur" placeholder="0"></div>
        <div class="fg"><label class="form-label">Date d'acquisition</label><input class="form-input" type="date" name="date_acquisition"></div>
        <div class="fg full"><label class="form-label">Notes / Observations</label><textarea class="form-input" name="notes" rows="2" style="resize:vertical" placeholder="Observations…"></textarea></div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">Annuler</button>
        <button type="submit" class="btn btn-primary">
          <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M2 8h12" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
          Enregistrer
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══ EDIT MODAL ══ -->
<div class="modal-bg" id="editModal">
  <div class="modal-inner">
    <h2>Modifier l'article</h2>
    <p class="modal-sub" id="editSub"></p>
    <form method="post">
      <input type="hidden" name="action" value="edit_mobilier">
      <input type="hidden" name="id" id="edit_id">
      <?php if($modeSiege): ?><input type="hidden" name="bureau_id" value="<?=$selectedBureauId?>"><?php endif; ?>
      <div class="form-grid">
        <div class="fg full"><label class="form-label">Désignation *</label><input class="form-input" type="text" name="designation" id="edit_designation" required></div>
        <div class="fg"><label class="form-label">N° Immo</label><input class="form-input" type="text" name="n_immo" id="edit_n_immo"></div>
        <div class="fg"><label class="form-label">Code article physique</label><input class="form-input" type="text" name="code_article_physique" id="edit_code_article_physique"></div>
        <div class="fg"><label class="form-label">Sous-famille</label><input class="form-input" type="text" name="sous_famille" id="edit_sous_famille"></div>
        <div class="fg"><label class="form-label">Modèle</label><input class="form-input" type="text" name="modele" id="edit_modele"></div>
        <div class="fg"><label class="form-label">N° Série</label><input class="form-input" type="text" name="n_serie" id="edit_n_serie"></div>
        <div class="fg"><label class="form-label">Quantité</label><input class="form-input" type="number" name="quantite" id="edit_quantite" min="1"></div>
        <div class="fg"><label class="form-label">État</label>
          <select class="form-input" name="etat_bien" id="edit_etat_bien">
            <?php foreach($etats as $et): ?><option><?=htmlspecialchars($et)?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="fg"><label class="form-label">Nom utilisateur</label><input class="form-input" type="text" name="nom_utilisateur" id="edit_nom_utilisateur"></div>
        <div class="fg"><label class="form-label">Matricule</label><input class="form-input" type="text" name="matricule" id="edit_matricule"></div>
        <div class="fg"><label class="form-label">Valeur (TND)</label><input class="form-input" type="number" step="0.01" name="valeur" id="edit_valeur"></div>
        <div class="fg"><label class="form-label">Date d'acquisition</label><input class="form-input" type="date" name="date_acquisition" id="edit_date_acquisition"></div>
        <div class="fg full"><label class="form-label">Notes</label><textarea class="form-input" name="notes" id="edit_notes" rows="2" style="resize:vertical"></textarea></div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">Annuler</button>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ DELETE MODAL ══ -->
<div class="modal-bg" id="delModal">
  <div class="del-inner">
    <h3>Supprimer cet article ?</h3>
    <p>Action irréversible.<br><strong id="delName"></strong></p>
    <div class="del-actions">
      <button class="btn btn-ghost" onclick="closeModal('delModal')">Annuler</button>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="delete_mobilier">
        <input type="hidden" name="id" id="del_id">
        <?php if($modeSiege): ?><input type="hidden" name="bureau_id" value="<?=$selectedBureauId?>"><?php endif; ?>
        <button type="submit" class="btn btn-danger">Supprimer</button>
      </form>
    </div>
  </div>
</div>

<?php
// ── Fonction : rendu du tableau mobilier ────────────────────────────
function renderMobilierTable(array $mobiliers, float $val_total, bool $canUpdate, bool $canDelete): void { ?>
<div class="table-wrap">
  <div class="table-header">
    <span class="table-title">Inventaire du mobilier</span>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <?php if($val_total>0): ?>
      <span style="font-size:12px;font-weight:600;color:var(--muted);">Valeur totale :
        <strong style="color:var(--ink)"><?=number_format($val_total,0,',',' ')?> TND</strong>
      </span>
      <?php endif; ?>
      <div class="table-search">
        <svg width="11" height="11" viewBox="0 0 16 16" fill="none"><circle cx="6.5" cy="6.5" r="4.5" stroke="currentColor" stroke-width="1.4"/><path d="M10 10l3.5 3.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
        <input type="text" placeholder="Filtrer…" oninput="filterTable(this.value)">
      </div>
    </div>
  </div>
  <div style="overflow-x:auto;">
  <table class="mob-table">
    <thead><tr>
      <th>N° Immo / Code</th><th>Désignation</th><th>Sous-famille</th>
      <th>Modèle</th><th>Qté</th><th>État</th><th>Utilisateur</th><th>Actions</th>
    </tr></thead>
    <tbody id="mobTbody">
      <?php if(empty($mobiliers)): ?>
      <tr><td colspan="8" class="no-rows">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" style="display:block;margin:0 auto 10px;opacity:.25"><rect x="2" y="7" width="20" height="13" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M2 11h20" stroke="currentColor" stroke-width="1.5"/></svg>
        Aucun article — cliquez sur "Ajouter un article"
      </td></tr>
      <?php else: foreach($mobiliers as $m):
        $etat=strtoupper(trim($m['etat_bien']??'FONCTIONNEL'));
        $ecls=(str_contains($etat,'NON FONC')||str_contains($etat,'REBUT'))?'eb-nfonc'
             :(str_contains($etat,'REPARATION')?'eb-rep'
             :(str_contains($etat,'FONCTIONNEL')?'eb-fonc':'eb-other'));
        $searchStr=strtolower(($m['designation']??'').' '.($m['n_immo']??'').' '.($m['code_article_physique']??'').' '.($m['sous_famille']??'').' '.($m['nom_utilisateur']??''));
      ?>
      <tr data-search="<?=htmlspecialchars($searchStr)?>">
        <td>
          <?php if(!empty($m['n_immo'])): ?><span class="code-cell"><?=htmlspecialchars($m['n_immo'])?></span><?php endif; ?>
          <?php if(!empty($m['code_article_physique'])): ?><br><span style="font-size:10px;color:var(--muted)"><?=htmlspecialchars($m['code_article_physique'])?></span><?php endif; ?>
          <?php if(empty($m['n_immo'])&&empty($m['code_article_physique'])): ?><span style="color:#D1D5DB">—</span><?php endif; ?>
        </td>
        <td>
          <strong><?=htmlspecialchars($m['designation']??'—')?></strong>
          <?php if(!empty($m['n_serie'])): ?><br><span style="font-size:10px;color:var(--muted)">N°série : <?=htmlspecialchars($m['n_serie'])?></span><?php endif; ?>
          <?php if(!empty($m['notes'])): ?><br><span style="font-size:11px;color:var(--muted)"><?=htmlspecialchars(substr($m['notes'],0,50))?></span><?php endif; ?>
        </td>
        <td><?=htmlspecialchars($m['sous_famille']??'—')?></td>
        <td><?=htmlspecialchars($m['modele']??'—')?></td>
        <td><span class="qty-chip"><?=intval($m['quantite']??1)?></span></td>
        <td><span class="etat-badge <?=$ecls?>"><?=htmlspecialchars($m['etat_bien']??'—')?></span></td>
        <td>
          <?php if(!empty($m['nom_utilisateur'])): ?>
            <?=htmlspecialchars($m['nom_utilisateur'])?>
            <?php if(!empty($m['matricule'])): ?><br><span style="font-size:10px;color:var(--muted)"><?=htmlspecialchars($m['matricule'])?></span><?php endif; ?>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td>
          <div class="action-btns">
            <?php if($canUpdate): ?>
            <button class="abtn" title="Modifier" onclick='openEditModal(<?=htmlspecialchars(json_encode($m),ENT_QUOTES)?>)'>
              <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M11.5 2.5a1.414 1.414 0 012 2L5 13H3v-2L11.5 2.5z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <?php endif; ?>
            <?php if($canDelete): ?>
            <button class="abtn abtn-del" title="Supprimer" onclick="openDelModal(<?=$m['id']?>,'<?=htmlspecialchars(addslashes($m['designation']??''))?>')">
              <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M2 4h12M5 4V3a1 1 0 011-1h4a1 1 0 011 1v1M6 7v5M10 7v5M3 4l1 9a1 1 0 001 1h6a1 1 0 001-1l1-9" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php }
?>

<script>
function filterBureaux(q){
  const sq=(q||'').toLowerCase().trim();
  document.querySelectorAll('.bureau-item').forEach(el=>{el.style.display=(!sq||el.dataset.search.includes(sq))?'':'none';});
}
function filterTable(q){
  const sq=(q||'').toLowerCase().trim();
  document.querySelectorAll('#mobTbody tr[data-search]').forEach(el=>{el.style.display=(!sq||el.dataset.search.includes(sq))?'':'none';});
}
function openAddModal(){ document.getElementById('addModal').classList.add('open'); }
function openEditModal(m){
  document.getElementById('edit_id').value                    = m.id||'';
  document.getElementById('edit_designation').value           = m.designation||'';
  document.getElementById('edit_n_immo').value                = m.n_immo||'';
  document.getElementById('edit_code_article_physique').value = m.code_article_physique||'';
  document.getElementById('edit_sous_famille').value          = m.sous_famille||'';
  document.getElementById('edit_modele').value                = m.modele||'';
  document.getElementById('edit_n_serie').value               = m.n_serie||'';
  document.getElementById('edit_quantite').value              = m.quantite||1;
  document.getElementById('edit_etat_bien').value             = m.etat_bien||'FONCTIONNEL';
  document.getElementById('edit_nom_utilisateur').value       = m.nom_utilisateur||'';
  document.getElementById('edit_matricule').value             = m.matricule||'';
  document.getElementById('edit_valeur').value                = m.valeur||'';
  document.getElementById('edit_date_acquisition').value      = m.date_acquisition||'';
  document.getElementById('edit_notes').value                 = m.notes||'';
  document.getElementById('editSub').textContent              = m.designation||'';
  document.getElementById('editModal').classList.add('open');
}
function openDelModal(id,name){
  document.getElementById('del_id').value=id;
  document.getElementById('delName').textContent=name;
  document.getElementById('delModal').classList.add('open');
}
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
document.getElementById('selDesig')?.addEventListener('change',function(){
  const w=document.getElementById('autreWrap'),a=document.querySelector('[name="designation_autre"]');
  if(this.value==='Autre'){w.style.display='block';a.required=true;this.required=false;}
  else{w.style.display='none';a.required=false;this.required=true;}
});
document.querySelector('#addModal form')?.addEventListener('submit',function(e){
  const sel=this.querySelector('[name="designation"]'),autre=this.querySelector('[name="designation_autre"]');
  if(sel&&sel.value==='Autre'){if(!autre.value.trim()){e.preventDefault();autre.focus();return;}sel.name='';autre.name='designation';}
});
document.querySelectorAll('.modal-bg').forEach(el=>{el.addEventListener('click',e=>{if(e.target===el)el.classList.remove('open');});});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-bg.open').forEach(m=>m.classList.remove('open'));});
</script>
</body>
</html>