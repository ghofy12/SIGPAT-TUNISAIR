<?php
require_once 'config.php';
if(!isLoggedIn()){ redirect('login.php'); }
$username = $_SESSION['username'] ?? 'Utilisateur';

$NORMES=['PDG'=>42,'Président Directeur Général'=>42,'Directeur Général Adjoint'=>42,'DGA'=>42,'Secrétaire Général'=>38,'Directeur Central'=>38,'Directeur'=>24,'Chef de Département'=>16,'Chef de Service'=>12,'Cadre'=>12,'Haute Maîtrise (seul)'=>10,'Haute Maîtrise (2)'=>15,'Secrétaire'=>9,'Maîtrise (seul)'=>9,'Maîtrise (2)'=>12,'Maîtrise (3)'=>18,];

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
  if($action==='add'){$f=['etage','ref_bureau','superficie','superficie_droit','mle','nom','prenom','emploi','l_fonct','l_entite','direction','direction_centrale','l_classf','statut','notes'];$pdo->prepare("INSERT INTO siege_bureaux (".implode(',',$f).") VALUES (".implode(',',array_fill(0,count($f),'?')).")")->execute(array_map(fn($k)=>($_POST[$k]??null)?:null,$f));}
  if($action==='edit'){$f=['ref_bureau','superficie','superficie_droit','mle','nom','prenom','emploi','l_fonct','l_entite','direction','direction_centrale','l_classf','statut','notes'];$v=array_map(fn($k)=>($_POST[$k]??null)?:null,$f);$v[]=$_POST['id'];$pdo->prepare("UPDATE siege_bureaux SET ".implode(', ',array_map(fn($k)=>"$k=?",$f))." WHERE id=?")->execute($v);}
  if($action==='delete')$pdo->prepare("DELETE FROM siege_bureaux WHERE id=?")->execute([$_POST['id']]);
  if($action==='upload_etage'&&!empty($_FILES['plan_etage']['tmp_name'])){
    $dir='documents/siege/etages/'; if(!is_dir($dir)) mkdir($dir,0755,true);
    $ext=strtolower(pathinfo($_FILES['plan_etage']['name'],PATHINFO_EXTENSION));
    $fn='etage_'.$ep.'.'.$ext;
    move_uploaded_file($_FILES['plan_etage']['tmp_name'],$dir.$fn);
  }
  header("Location: ?etage=$ep"); exit;
}

$tous=[];try{$tous=$pdo->query("SELECT * FROM siege_bureaux ORDER BY etage ASC, ref_bureau ASC")->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){}
$by_etage=[];foreach($tous as $b)$by_etage[intval($b['etage'])][]=$b;
$stats=[];foreach($ETAGES as $n=>$_){
  $l=$by_etage[$n]??[];
  $occ=count(array_filter($l,fn($b)=>!empty($b['mle'])));
  $hn=count(array_filter($l,fn($b)=>!empty($b['l_fonct'])&&isset($NORMES[$b['l_fonct']])&&!empty($b['superficie'])&&(floatval($b['superficie'])-$NORMES[$b['l_fonct']])>2));
  $stats[$n]=['total'=>count($l),'occupes'=>$occ,'libres'=>count($l)-$occ,'hn'=>$hn,'m2'=>array_sum(array_column($l,'superficie'))];
}
$ea=intval($_GET['etage']??0); if(!isset($ETAGES[$ea]))$ea=0;
$econf=$ETAGES[$ea];
$c1ea=explode(',',$econf['color'])[0]; $c2ea=explode(',',$econf['color'])[1];

$plans_etage = [];
foreach($ETAGES as $n=>$_){
  $dir = 'documents/siege/etages/';
  foreach(['jpg','jpeg','png','webp','pdf'] as $ext){
    $path = $dir.'etage_'.$n.'.'.$ext;
    if(file_exists($path)){ $plans_etage[$n]=$path; break; }
  }
}
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
::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-track{background:var(--bg);}
::-webkit-scrollbar-thumb{background:rgba(0,0,0,.15);border-radius:4px;}
::-webkit-scrollbar-thumb:hover{background:rgba(0,0,0,.25);}
.navbar{background:var(--white);border-bottom:3px solid var(--red);box-shadow:0 2px 10px rgba(0,0,0,.06);height:64px;padding:0 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:200;flex-shrink:0;}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.nav-logo{height:38px;width:auto;max-width:110px;object-fit:contain;}
.nav-brand-text{font-size:14px;font-weight:700;color:var(--red);}
.nav-bc{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);}
.nav-bc a{color:var(--muted);text-decoration:none;}.nav-bc a:hover{color:var(--red);}
.nav-bc .sep{opacity:.4;}
.nav-bc strong{color:var(--ink);font-weight:600;}
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
.fi:hover{background:var(--bg);}
.fi.active{background:rgba(0,0,0,.04);}
.fi-badge{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:white;flex-shrink:0;}
.fi-info{flex:1;min-width:0;}
.fi-name{font-size:12px;font-weight:600;color:var(--ink);}
.fi.active .fi-name{color:var(--ac);}
.fi-sub{font-size:10px;color:var(--muted);margin-top:1px;}
.fi-n{font-size:12px;font-weight:700;color:var(--muted);flex-shrink:0;}
.fi.active .fi-n{color:var(--ac);}
.col2{width:305px;min-width:305px;background:var(--bg);border-right:1.5px solid var(--rule);display:flex;flex-direction:column;overflow:hidden;}
.c2-top{padding:12px;background:var(--white);border-bottom:1.5px solid var(--rule);flex-shrink:0;box-shadow:0 2px 6px rgba(0,0,0,.04);}
.c2-name{font-size:14px;font-weight:700;color:var(--navy);margin-bottom:1px;}
.c2-sub{font-size:11px;color:var(--muted);margin-bottom:8px;}
/* 3-col stats (no m²) */
.c2-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:5px;margin-bottom:6px;}
.c2-stat{background:var(--bg);border-radius:7px;padding:6px 7px;text-align:center;border:1.5px solid var(--rule);}
.c2-stat.clickable{cursor:pointer;transition:all .15s;user-select:none;}
.c2-stat.clickable:hover{border-color:var(--ac);box-shadow:0 2px 8px rgba(0,0,0,.08);transform:translateY(-1px);}
.c2-stat.active-stat{border-color:var(--ac)!important;background:#fff;}
.c2-sv{font-size:13px;font-weight:700;color:var(--navy);}
.c2-sl{font-size:9px;color:var(--muted);margin-top:1px;}
/* HN bar */
.hn-bar{display:flex;align-items:center;justify-content:space-between;padding:7px 11px;border-radius:8px;border:1.5px solid #FDE68A;background:#FFFBEB;margin-bottom:6px;cursor:pointer;user-select:none;transition:all .15s;}
.hn-bar:hover{background:#FEF3C7;}
.hn-bar.active-stat{border-color:#D97706!important;background:#FEF3C7;}
.hn-bar-left{display:flex;align-items:center;gap:6px;}
.hn-bar-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#D97706;}
.hn-bar-count{background:#DC2626;color:white;font-size:10px;font-weight:700;padding:1px 8px;border-radius:10px;}
/* Stat dropdowns */
.stat-dropdown{display:none;margin-bottom:7px;border-radius:9px;overflow:hidden;border:1.5px solid var(--rule);background:var(--white);box-shadow:0 6px 20px rgba(0,0,0,.1);}
.stat-dropdown.open{display:block;animation:ddIn .15s ease;}
@keyframes ddIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
.stat-dd-hdr{display:flex;align-items:center;justify-content:space-between;padding:8px 11px;background:var(--bg);border-bottom:1px solid var(--rule);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;}
.stat-dd-close{cursor:pointer;color:var(--muted);font-size:14px;line-height:1;padding:0 3px;}
.stat-dd-list{max-height:200px;overflow-y:auto;display:flex;flex-direction:column;}
.stat-dd-item{display:flex;align-items:center;gap:8px;padding:8px 11px;cursor:pointer;border-bottom:1px solid var(--rule);transition:background .1s;}
.stat-dd-item:last-child{border-bottom:none;}
.stat-dd-item:hover{background:var(--bg);}
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
.bcard.sel{border-color:var(--ac);box-shadow:0 4px 16px rgba(0,0,0,.1);}
.bcard.hn-c{border-color:#FDE68A;}
.bcard.sel.hn-c{border-color:var(--ac);}
.bc-top{display:flex;align-items:center;gap:6px;margin-bottom:4px;}
.bc-ref{font-family:monospace;font-size:10px;font-weight:700;padding:2px 7px;border-radius:5px;}
.bc-st{font-size:10px;font-weight:600;padding:2px 7px;border-radius:5px;margin-left:auto;}
.bc-st.occ{background:#DCFCE7;color:#15803D;}.bc-st.lib{background:#FEE2E2;color:#DC2626;}.bc-st.dep{background:#FEF3C7;color:#D97706;}
.bc-hn{font-size:9px;font-weight:700;padding:1px 5px;border-radius:4px;background:#FEF3C7;color:#D97706;}
.bc-name{font-size:12px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.bc-meta{display:flex;align-items:center;gap:7px;margin-top:3px;font-size:11px;color:var(--muted);}
.bc-mle{font-family:monospace;font-size:10px;font-weight:600;color:var(--navy);}
.bc-dir-t{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px;}
.bc-m2{margin-left:auto;font-family:monospace;font-size:10px;}
.no-res{padding:32px 14px;text-align:center;color:var(--muted);font-size:13px;}
.col3{flex:1;min-width:0;overflow-y:auto;background:var(--bg);}
.empty-det{height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;color:var(--muted);padding:40px;text-align:center;}
.empty-det svg{opacity:.25;}
.empty-det p{font-size:14px;line-height:1.7;max-width:280px;}
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
.dh-actions{display:flex;gap:7px;flex-shrink:0;}
.sec{background:var(--white);border-radius:13px;padding:17px 19px;margin-bottom:12px;border:1.5px solid var(--rule);box-shadow:var(--shadow);}
.sec.sec-hn{border-color:#FDE68A;background:#FFFDF5;}
.sec-t{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:12px;padding-bottom:9px;border-bottom:1.5px solid var(--rule);}
.fg{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.f{background:var(--bg);border-radius:9px;padding:9px 12px;}
.f.full{grid-column:1/-1;}
.f.hi{background:rgba(109,40,217,.06);border:1px solid rgba(109,40,217,.15);}
.fl{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:2px;}
.fv{font-size:13px;font-weight:600;color:var(--ink);}
.occ-p{display:flex;align-items:center;gap:13px;padding:12px;background:var(--bg);border-radius:10px;}
.occ-av{width:46px;height:46px;border-radius:12px;display:grid;place-items:center;font-size:17px;font-weight:700;color:white;flex-shrink:0;}
.occ-name{font-size:14px;font-weight:700;color:var(--navy);}
.occ-emp{font-size:12px;color:var(--muted);margin-top:1px;}
.occ-mle{display:inline-block;margin-top:5px;font-family:monospace;font-size:11px;font-weight:700;padding:2px 8px;border-radius:5px;}
.surf-bar{margin-top:8px;}
.sb-track{height:6px;background:var(--rule);border-radius:3px;overflow:hidden;}
.sb-fill{height:100%;border-radius:3px;}
.sb-fill.ok{background:#22C55E;}.sb-fill.over{background:#F59E0B;}.sb-fill.under{background:#EF4444;}
.sb-lbls{display:flex;justify-content:space-between;font-size:10px;color:var(--muted);margin-top:4px;}
.sb-diff{font-size:12px;font-weight:700;margin-top:4px;}
.sb-diff.pos{color:#15803D;}.sb-diff.neg{color:#DC2626;}.sb-diff.neu{color:var(--muted);}
.na{display:flex;align-items:flex-start;gap:7px;padding:10px 13px;border-radius:9px;font-size:12px;font-weight:600;margin-top:9px;line-height:1.5;}
.na.ok{background:#DCFCE7;color:#15803D;border:1px solid #BBF7D0;}
.na.over{background:#FEE2E2;color:#DC2626;border:1px solid #FECACA;}
.na.warn{background:#FEF3C7;color:#D97706;border:1px solid #FDE68A;}
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:none;font-family:inherit;transition:all .15s;text-decoration:none;}
.btn-p{color:white;background:linear-gradient(135deg,<?=$econf['color']?>);}.btn-p:hover{opacity:.88;}
.btn-g{background:transparent;color:var(--muted);border:1.5px solid var(--rule);}.btn-g:hover{background:var(--bg);color:var(--ink);}
.btn-d{background:#FEE2E2;color:#DC2626;border:1px solid #FECACA;}.btn-d:hover{background:#FECACA;}
.btn-sm{padding:5px 10px;font-size:11px;}
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
.fg-actions{padding:0 24px 20px;display:flex;gap:8px;justify-content:flex-end;}
.lightbox{display:none;position:fixed;inset:0;z-index:600;background:rgba(0,0,0,.9);align-items:center;justify-content:center;cursor:zoom-out;}
.lightbox.open{display:flex;}
.lightbox img{max-width:92vw;max-height:90vh;border-radius:10px;}
.lb-close{position:absolute;top:16px;right:16px;width:34px;height:34px;border-radius:8px;background:rgba(255,255,255,.15);border:none;cursor:pointer;display:grid;place-items:center;color:white;}
mark{background:#FEF3C7;border-radius:3px;padding:0 1px;}
.plan-etage-panel{padding:20px 24px 32px;}
.pe-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;}
.pe-title{font-size:18px;font-weight:700;color:var(--navy);}
.pe-sub{font-size:13px;color:var(--muted);margin-top:3px;}
.pe-badge{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;padding:4px 12px;border-radius:20px;color:white;}
.pe-actions{display:flex;gap:8px;align-items:center;}
.pe-upload-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:inherit;transition:opacity .2s;color:white;}
.pe-upload-btn:hover{opacity:.88;}
.pe-upload-input{display:none;}
.pe-img-wrap{border-radius:14px;overflow:hidden;border:1.5px solid var(--rule);background:var(--white);box-shadow:var(--shadow);position:relative;}
.pe-img-wrap img{width:100%;display:block;cursor:zoom-in;transition:transform .2s;}
.pe-img-wrap img:hover{transform:scale(1.005);}
.pe-img-bar{display:flex;align-items:center;justify-content:space-between;padding:10px 16px;background:var(--bg);border-top:1.5px solid var(--rule);font-size:12px;color:var(--muted);}
.pe-placeholder{border-radius:14px;border:2px dashed var(--rule);background:var(--white);padding:60px 40px;text-align:center;box-shadow:var(--shadow);}
.pe-ph-icon{width:64px;height:64px;border-radius:16px;margin:0 auto 16px;display:grid;place-items:center;}
.pe-ph-title{font-size:16px;font-weight:700;color:var(--navy);margin-bottom:8px;}
.pe-ph-sub{font-size:13px;color:var(--muted);line-height:1.7;margin-bottom:20px;}
.pe-ph-btn{display:inline-flex;align-items:center;gap:7px;padding:11px 22px;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:inherit;transition:opacity .2s;color:white;}
.pe-ph-btn:hover{opacity:.88;}
</style>
</head>
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
      <div class="fi-n"><?=$s['total']?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- COL 2 -->
<div class="col2">
  <div class="c2-top">
    <div class="c2-name" id="c2Name"><?=htmlspecialchars($econf['label'])?></div>
    <div class="c2-sub" id="c2Sub"><?=htmlspecialchars($econf['sub'])?></div>

    <!-- STATS ROW: Total | Occ. | Libres▾ (clickable) -->
    <div class="c2-stats" id="c2Stats">
      <?php $s=$stats[$ea]; ?>
      <div class="c2-stat"><div class="c2-sv"><?=$s['total']?></div><div class="c2-sl">Total</div></div>
      <div class="c2-stat"><div class="c2-sv" style="color:#15803D"><?=$s['occupes']?></div><div class="c2-sl">Occ.</div></div>
      <div class="c2-stat clickable" id="statLibre" onclick="toggleDD('libre')">
        <div class="c2-sv" style="color:#0369A1"><?=$s['libres']?></div>
        <div class="c2-sl" style="color:#0369A1">Libres ▾</div>
      </div>
    </div>

    <!-- HN clickable bar -->
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

    <!-- Dropdown Libres -->
    <div class="stat-dropdown" id="ddLibre">
      <div class="stat-dd-hdr">
        <span style="color:#0369A1;">Bureaux libres — <span id="ddLibreFloor"><?=htmlspecialchars($econf['label'])?></span></span>
        <span class="stat-dd-close" onclick="closeDD('libre')">✕</span>
      </div>
      <div class="stat-dd-list" id="ddLibreList"></div>
    </div>

    <!-- Dropdown HN -->
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
    <button class="c2-add" onclick="openAdd()">
      <svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M2 8h12" stroke="white" stroke-width="2.2" stroke-linecap="round"/></svg>
      Ajouter un bureau
    </button>
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
    <button onclick="showPlanEtage()" style="display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:10px;border:1.5px solid var(--rule);background:var(--white);color:var(--muted);font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .2s;margin-top:4px;" onmouseover="this.style.borderColor='<?=$c1ea?>';this.style.color='<?=$c1ea?>'" onmouseout="this.style.borderColor='rgba(0,0,0,.07)';this.style.color='#6B7280'">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M3 9h18M9 9v12M3 15h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      Afficher le plan de l'étage
    </button>
  </div>
  <div id="planEtagePanel" style="display:none;">
    <div class="plan-etage-panel" id="planEtageContent"></div>
  </div>
  <div id="detCont" style="display:none;"></div>
</div>
</div>
</div>

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
            <option value="PDG">PDG (42 m²)</option><option value="Directeur Général Adjoint">DGA (42 m²)</option>
            <option value="Secrétaire Général">Secrétaire Général (38 m²)</option><option value="Directeur Central">Directeur Central (38 m²)</option>
            <option value="Directeur">Directeur (24 m²)</option><option value="Chef de Département">Chef de Département (16 m²)</option>
            <option value="Chef de Service">Chef de Service (12 m²)</option><option value="Cadre">Cadre (12 m²)</option>
            <option value="Haute Maîtrise (seul)">Haute Maîtrise — 1 seul (10 m²)</option><option value="Haute Maîtrise (2)">Haute Maîtrise — 2 agents (15 m²)</option>
            <option value="Secrétaire">Secrétaire (9 m²)</option><option value="Maîtrise (seul)">Maîtrise — 1 seul (9 m²)</option>
            <option value="Maîtrise (2)">Maîtrise — 2 agents (12 m²)</option><option value="Maîtrise (3)">Maîtrise — 3 agents (18 m²)</option>
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
            <option value="PDG">PDG (42 m²)</option><option value="Directeur Général Adjoint">DGA (42 m²)</option>
            <option value="Secrétaire Général">Secrétaire Général (38 m²)</option><option value="Directeur Central">Directeur Central (38 m²)</option>
            <option value="Directeur">Directeur (24 m²)</option><option value="Chef de Département">Chef de Département (16 m²)</option>
            <option value="Chef de Service">Chef de Service (12 m²)</option><option value="Cadre">Cadre (12 m²)</option>
            <option value="Haute Maîtrise (seul)">Haute Maîtrise — 1 seul (10 m²)</option><option value="Haute Maîtrise (2)">Haute Maîtrise — 2 agents (15 m²)</option>
            <option value="Secrétaire">Secrétaire (9 m²)</option><option value="Maîtrise (seul)">Maîtrise — 1 seul (9 m²)</option>
            <option value="Maîtrise (2)">Maîtrise — 2 agents (12 m²)</option><option value="Maîtrise (3)">Maîtrise — 3 agents (18 m²)</option>
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

<!-- LIGHTBOX -->
<div class="lightbox" id="lightbox" onclick="closeLB()">
  <button class="lb-close" onclick="closeLB()"><svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M3 3l10 10M13 3L3 13" stroke="white" stroke-width="1.8" stroke-linecap="round"/></svg></button>
  <img id="lbImg" src="" alt="">
</div>

<script>
const ALL=<?=json_encode(array_values($tous),JSON_UNESCAPED_UNICODE)?>;
const NORMES=<?=json_encode($NORMES,JSON_UNESCAPED_UNICODE)?>;
const ECFG=<?=json_encode($ETAGES,JSON_UNESCAPED_UNICODE)?>;
const PLANS_ETAGE=<?=json_encode($plans_etage,JSON_UNESCAPED_UNICODE)?>;
let curFloor=<?=$ea?>,activeId=null,openDD=null;
const byE={};ALL.forEach(b=>{const e=parseInt(b.etage);if(!byE[e])byE[e]=[];byE[e].push(b);});
const esc=s=>s?String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'):'';
const fmt=(v,d=2)=>v!=null&&v!==''?parseFloat(v).toLocaleString('fr-TN',{minimumFractionDigits:d,maximumFractionDigits:d}):'—';
const bclass=s=>{const l=(s||'').toLowerCase();return l.includes('occ')?'occ':l.includes('lib')?'lib':'dep';};
const isHN=b=>b.l_fonct&&NORMES[b.l_fonct]&&b.superficie&&(parseFloat(b.superficie)-NORMES[b.l_fonct])>2;
const isLibre=b=>!b.mle||!String(b.mle).trim();
const hl=(t,q)=>{if(!q||!t)return esc(t||'');return String(t).replace(new RegExp('('+q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi'),'<mark>$1</mark>');};

/* ── Dropdown helpers ───────────────────────────────────── */
function toggleDD(type){
  if(openDD===type){closeDD(type);return;}
  if(openDD)closeDD(openDD);
  openDD=type;
  const dd=document.getElementById(type==='hn'?'ddHN':'ddLibre');
  const btn=document.getElementById(type==='hn'?'statHN':'statLibre');
  dd.classList.add('open');
  btn.classList.add('active-stat');
  renderDD(type);
}
function closeDD(type){
  openDD=null;
  document.getElementById(type==='hn'?'ddHN':'ddLibre').classList.remove('open');
  document.getElementById(type==='hn'?'statHN':'statLibre').classList.remove('active-stat');
}
function renderDD(type){
  const list=byE[curFloor]||[];
  const cfg=ECFG[curFloor];
  const c1=cfg.color.split(',')[0];
  if(type==='hn'){
    document.getElementById('ddHNFloor').textContent=cfg.label;
    const items=list.filter(isHN);
    const cont=document.getElementById('ddHNList');
    if(!items.length){cont.innerHTML='<div class="stat-dd-empty">Aucun bureau hors norme sur cet étage.</div>';return;}
    cont.innerHTML=items.map(b=>{
      const diff=parseFloat(b.superficie)-NORMES[b.l_fonct];
      const nm=[b.nom,b.prenom].filter(Boolean).join(' ')||'—';
      return `<div class="stat-dd-item" onclick="jumpAndClose(${b.id})">
        <span class="stat-dd-ref" style="color:${c1}">${esc(b.ref_bureau||'—')}</span>
        <span class="stat-dd-name">${esc(nm)}</span>
        <span class="stat-dd-val" style="color:#DC2626">+${fmt(diff,1)} m²</span>
      </div>`;
    }).join('');
  } else {
    document.getElementById('ddLibreFloor').textContent=cfg.label;
    const items=list.filter(isLibre);
    const cont=document.getElementById('ddLibreList');
    if(!items.length){cont.innerHTML='<div class="stat-dd-empty">Aucun bureau libre sur cet étage.</div>';return;}
    cont.innerHTML=items.map(b=>`<div class="stat-dd-item" onclick="jumpAndClose(${b.id})">
      <span class="stat-dd-ref" style="color:#0369A1">${esc(b.ref_bureau||'—')}</span>
      <span class="stat-dd-name">${esc(b.direction_centrale||b.direction||'Bureau libre')}</span>
      ${b.superficie?`<span class="stat-dd-val" style="color:#0369A1">${fmt(b.superficie,1)} m²</span>`:''}
    </div>`).join('');
  }
}
function jumpAndClose(id){
  if(openDD)closeDD(openDD);
  selBureau(id);
  setTimeout(()=>{
    const card=document.querySelector(`.bcard[data-id="${id}"]`);
    if(card)card.scrollIntoView({behavior:'smooth',block:'nearest'});
  },60);
}

/* ── Floor switch ───────────────────────────────────────── */
function switchFloor(n,push){
  curFloor=n;
  if(openDD)closeDD(openDD);
  document.getElementById('listSearch').value='';
  document.querySelectorAll('.fi').forEach(f=>f.classList.remove('active'));
  document.getElementById('fi'+n).classList.add('active');
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
  // Update stats (3 cols, no m²)
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
  if(document.getElementById('planEtagePanel').style.display!=='none'){showPlanEtage();}else{clearDet();}
}

function renderCards(list,q,dir){
  const sq=(q||'').toLowerCase().trim(),sd=(dir||'').toLowerCase().trim();
  const f=sq||sd?list.filter(b=>(!sq||(b.mle||'').toLowerCase().includes(sq)||(b.nom||'').toLowerCase().includes(sq)||(b.prenom||'').toLowerCase().includes(sq)||(b.ref_bureau||'').toLowerCase().includes(sq)||(b.direction||'').toLowerCase().includes(sq)||(b.direction_centrale||'').toLowerCase().includes(sq))&&(!sd||(b.direction_centrale||b.direction||'').toLowerCase().includes(sd))):list;
  const cont=document.getElementById('c2List');
  if(!f.length){cont.innerHTML=`<div class="no-res">${sq||sd?'Aucun résultat.':'Aucun bureau. Cliquez sur Ajouter.'}</div>`;return;}
  cont.innerHTML='';
  f.forEach(b=>{
    const occ=b.mle&&b.mle.trim();const st=b.statut||(occ?'Occupé':'Libre');const bc=bclass(st);const hn=isHN(b);
    const nm=occ?[b.nom,b.prenom].filter(Boolean).join(' '):'';
    const cfg=ECFG[parseInt(b.etage)]||{color:'#999,#bbb'};const c=cfg.color.split(',')[0];
    const card=document.createElement('div');
    card.className='bcard'+(hn?' hn-c':'')+(b.id==activeId?' sel':'');
    card.dataset.id=b.id;
    card.innerHTML=`<div class="bc-top">
      <span class="bc-ref" style="background:${c}18;color:${c}">${hl(b.ref_bureau||'—',sq)}</span>
      ${hn?'<span class="bc-hn">⚠ Norme</span>':''}
      <span class="bc-st ${bc}">${esc(st)}</span>
    </div>
    <div class="bc-name">${occ?hl(nm,sq):'<span style="color:var(--muted);font-weight:400">Bureau libre</span>'}</div>
    <div class="bc-meta">
      ${occ?`<span class="bc-mle">${hl(b.mle,sq)}</span>`:''}
      <span class="bc-dir-t">${hl(b.direction_centrale||b.direction||'',sq)}</span>
      ${b.superficie?`<span class="bc-m2">${fmt(b.superficie,1)} m²</span>`:''}
    </div>`;
    card.onclick=()=>selBureau(b.id);
    cont.appendChild(card);
  });
  if(sq.length>=3&&f.length===1)selBureau(f[0].id);
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
  activeId=id;
  document.querySelectorAll('.bcard').forEach(c=>c.classList.remove('sel'));
  const c=document.querySelector(`.bcard[data-id="${id}"]`);if(c)c.classList.add('sel');
  const b=ALL.find(x=>x.id==id);if(!b)return;
  document.getElementById('emptyDet').style.display='none';
  document.getElementById('planEtagePanel').style.display='none';
  document.getElementById('detCont').style.display='block';
  document.getElementById('detCont').innerHTML=buildDet(b);
}

function clearDet(){activeId=null;document.getElementById('emptyDet').style.display='flex';document.getElementById('detCont').style.display='none';document.getElementById('planEtagePanel').style.display='none';}

function showPlanEtage(){
  activeId=null;
  document.querySelectorAll('.bcard').forEach(c=>c.classList.remove('sel'));
  document.getElementById('emptyDet').style.display='none';
  document.getElementById('detCont').style.display='none';
  document.getElementById('planEtagePanel').style.display='block';
  const cfg=ECFG[curFloor];
  const c1=cfg.color.split(',')[0],c2=cfg.color.split(',')[1];
  const plan=PLANS_ETAGE[curFloor]||null;
  const etageLabel=cfg.label;
  let html=`<div class="pe-header">
    <div>
      <div class="pe-title">Plan du ${esc(etageLabel)}</div>
      <div class="pe-sub">Plan architectural de l'étage — disposition des bureaux</div>
    </div>
    <div class="pe-actions">
      <span class="pe-badge" style="background:linear-gradient(135deg,${c1},${c2})">${esc(cfg.short)}</span>
      <form method="post" enctype="multipart/form-data" style="display:inline">
        <input type="hidden" name="action" value="upload_etage">
        <input type="hidden" name="etage" value="${curFloor}">
        <input type="file" name="plan_etage" accept=".jpg,.jpeg,.png,.webp,.pdf" class="pe-upload-input" id="peUpload" onchange="this.form.submit()">
        <label for="peUpload" class="pe-upload-btn" style="background:linear-gradient(135deg,${c1},${c2});cursor:pointer">
          <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M8 12V4M5 7l3-3 3 3M3 13h10" stroke="white" stroke-width="1.5" stroke-linecap="round"/></svg>
          ${plan?'Remplacer le plan':'Importer un plan'}
        </label>
      </form>
    </div>
  </div>`;
  if(plan){
    const isPdf=plan.toLowerCase().endsWith('.pdf');const fname=plan.split('/').pop();
    const links=`<div style="display:flex;gap:8px"><a href="${esc(plan)}" target="_blank" style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:7px;background:var(--bg);border:1.5px solid var(--rule);color:var(--muted);text-decoration:none;font-size:11px;font-weight:600">↗ Ouvrir</a><a href="${esc(plan)}" download style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:7px;background:var(--bg);border:1.5px solid var(--rule);color:var(--muted);text-decoration:none;font-size:11px;font-weight:600">↓ Télécharger</a></div>`;
    if(isPdf){html+=`<div class="pe-img-wrap"><iframe src="${esc(plan)}" style="width:100%;height:70vh;border:none;display:block;"></iframe><div class="pe-img-bar"><span>📄 ${esc(fname)}</span>${links}</div></div>`;}
    else{html+=`<div class="pe-img-wrap"><img src="${esc(plan)}" alt="Plan ${esc(etageLabel)}" onclick="openLB('${esc(plan)}')"><div class="pe-img-bar"><span>🗺️ ${esc(fname)}</span>${links}</div></div>`;}
  }else{
    html+=`<div class="pe-placeholder"><div class="pe-ph-icon" style="background:${c1}18"><svg width="28" height="28" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="2" stroke="${c1}" stroke-width="1.5"/><path d="M3 9h18M9 9v12M3 15h6" stroke="${c1}" stroke-width="1.5" stroke-linecap="round"/></svg></div>
      <div class="pe-ph-title">Aucun plan importé pour cet étage</div>
      <div class="pe-ph-sub">Importez le plan architectural au format image (JPG, PNG, WebP)<br>ou document PDF pour l'afficher ici.</div>
      <form method="post" enctype="multipart/form-data" style="display:inline">
        <input type="hidden" name="action" value="upload_etage"><input type="hidden" name="etage" value="${curFloor}">
        <input type="file" name="plan_etage" accept=".jpg,.jpeg,.png,.webp,.pdf" class="pe-upload-input" id="peUpload2" onchange="this.form.submit()">
        <label for="peUpload2" class="pe-ph-btn" style="background:linear-gradient(135deg,${c1},${c2});cursor:pointer">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M8 12V4M5 7l3-3 3 3M3 13h10" stroke="white" stroke-width="1.5" stroke-linecap="round"/></svg>
          Importer le plan de l'étage
        </label>
      </form></div>`;
  }
  document.getElementById('planEtageContent').innerHTML=html;
}

function buildDet(b){
  const occ=b.mle&&b.mle.trim();const st=b.statut||(occ?'Occupé':'Libre');const bc=bclass(st);
  const ini=occ?((b.nom||'?').charAt(0)+(b.prenom||'').charAt(0)).toUpperCase():'—';
  const cfg=ECFG[parseInt(b.etage)]||{color:'#0F2563,#1D4ED8',label:'?',short:'?'};
  const c1=cfg.color.split(',')[0],c2=cfg.color.split(',')[1];
  const hn=isHN(b);
  let surfH='';
  if(b.superficie){
    const norme=b.l_fonct&&NORMES[b.l_fonct]?NORMES[b.l_fonct]:null;
    const reel=parseFloat(b.superficie),droit=b.superficie_droit?parseFloat(b.superficie_droit):null;
    const ref=droit||norme;let barH='',alertH='';
    if(ref){const pct=Math.min(100,Math.round(reel/ref*100));const diff=reel-ref;const cls=Math.abs(diff)<0.5?'ok':diff>0?'over':'under';const dc=Math.abs(diff)<0.5?'neu':diff>0?'neg':'pos';
      barH=`<div class="surf-bar"><div class="sb-track"><div class="sb-fill ${cls}" style="width:${pct}%"></div></div>
        <div class="sb-lbls"><span>${fmt(reel,2)} m²</span><span>Réf: ${fmt(ref,2)} m²</span></div>
        <div class="sb-diff ${dc}">${diff>0?'+':''}${fmt(diff,2)} m² (${pct}%)</div></div>`;}
    if(norme){const d=reel-norme;
      if(Math.abs(d)<=2)alertH=`<div class="na ok"><svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M5 10l4 4 6-8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>Conforme à la norme (${norme} m² pour ${esc(b.l_fonct)})</div>`;
      else if(d>2)alertH=`<div class="na over"><svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M10 4l6.5 12H3.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 9v4M10 15h.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>Dépassement +${fmt(d,2)} m² — norme: ${norme} m² (${esc(b.l_fonct)})</div>`;
      else alertH=`<div class="na warn">Surface inférieure de ${fmt(Math.abs(d),2)} m² à la norme (${norme} m²)</div>`;}
    surfH=`<div class="f full ${hn?'':'hi'}"><div class="fl">Surface réelle vs référence</div>
      <div class="fv">${fmt(reel,2)} m² <span style="font-size:11px;color:var(--muted);font-weight:400">(droit: ${droit?fmt(droit,2)+' m²':'N/A'})</span></div>${barH}${alertH}</div>`;}
  const f=(l,v,hi=false,full=false)=>v?`<div class="f${hi?' hi':''}${full?' full':''}"><div class="fl">${l}</div><div class="fv">${esc(v)}</div></div>`:'';
  return`<div class="dw">
  <div class="dh">
    <div>
      <div class="dh-bc"><span>Siège Social</span><span style="opacity:.4">›</span><span>${esc(cfg.label)}</span><span style="opacity:.4">›</span><span style="color:${c1}">${esc(b.ref_bureau||'—')}</span></div>
      <div class="dh-title">Bureau ${esc(b.ref_bureau||'—')}</div>
      <div class="dh-sub">${[b.l_entite,b.direction_centrale].filter(Boolean).map(esc).join(' · ')}</div>
      <div class="dh-badges">
        <span class="dh-st ${bc}">${esc(st)}</span>
        ${hn?'<span class="dh-hn">⚠ Hors norme</span>':''}
      </div>
    </div>
    <div class="dh-actions">
      <button class="btn btn-g btn-sm" onclick="openEdit(${b.id})">
        <svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M11.5 2.5a1.414 1.414 0 0 1 2 2L5 13H3v-2L11.5 2.5z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>Modifier
      </button>
      <button class="btn btn-d btn-sm" onclick="openDel(${b.id},'${esc(b.ref_bureau||'')}',${b.etage})">
        <svg width="11" height="11" viewBox="0 0 16 16" fill="none"><path d="M2 4h12M5 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1M6 7v5M10 7v5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>Supprimer
      </button>
    </div>
  </div>
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
  </div>`;
}

function openAdd(){document.getElementById('addEtage').value=curFloor;document.getElementById('addFloorLbl').textContent=ECFG[curFloor]?.label||'';openModal('addModal');}
function openEdit(id){const b=ALL.find(x=>x.id==id);if(!b)return;['ref_bureau','superficie','superficie_droit','mle','nom','prenom','emploi','l_entite','direction','direction_centrale','l_classf','notes'].forEach(k=>{const el=document.getElementById('e_'+k);if(el)el.value=b[k]||'';});const ss=document.getElementById('e_statut');if(ss)ss.value=b.statut||'Occupé';const sf=document.getElementById('e_l_fonct');if(sf)sf.value=b.l_fonct||'';document.getElementById('eId').value=id;document.getElementById('eEtage').value=b.etage;openModal('editModal');}
function openDel(id,lbl,e){document.getElementById('delId').value=id;document.getElementById('delEtage').value=e;document.getElementById('delLbl').textContent='Bureau '+lbl;openModal('deleteModal');}
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
function openLB(src){document.getElementById('lbImg').src=src;document.getElementById('lightbox').classList.add('open');}
function closeLB(){document.getElementById('lightbox').classList.remove('open');document.getElementById('lbImg').src='';}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeModal('addModal');closeModal('editModal');closeModal('deleteModal');closeLB();if(openDD)closeDD(openDD);}});
document.querySelectorAll('.modal-bg').forEach(el=>el.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');}));
switchFloor(curFloor,false);
</script>
</body>
</html>