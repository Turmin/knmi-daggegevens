<?php
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/safe.php');
if($_SERVER['REQUEST_METHOD'] == 'POST') {
	if(isset($_POST['datum'],$_POST['yyyymmdd'],$_SESSION['startDatum'],$_SESSION['eindDatum'])
		&& strlen($_POST['datum']) == 10 && strlen($_POST['yyyymmdd']) == 10) {
		$startDatum = new DateTime($_SESSION['startDatum']);
		$eindDatum = new DateTime($_SESSION['eindDatum']);
		$datum = new DateTime($_POST['datum']);
		$datumNL = $datum->format('d-m-Y');
		
		if($datum >= $startDatum && $datum <= $eindDatum) {
			$sql = "UPDATE knmi SET
			yyyymmdd = '".$mysqli->real_escape_string($_POST['yyyymmdd'])."',
			ddvec = '".$mysqli->real_escape_string($_POST['ddvec'])."',
			fhvec = '".$mysqli->real_escape_string($_POST['fhvec'])."',
			fg = '".$mysqli->real_escape_string($_POST['fg'])."',
			fhx = '".$mysqli->real_escape_string($_POST['fhx'])."',
			fhxh = '".$mysqli->real_escape_string($_POST['fhxh'])."',
			fhn = '".$mysqli->real_escape_string($_POST['fhn'])."',
			fhnh = '".$mysqli->real_escape_string($_POST['fhnh'])."',
			fxx = '".$mysqli->real_escape_string($_POST['fxx'])."',
			fxxh = '".$mysqli->real_escape_string($_POST['fxxh'])."',
			tg = '".$mysqli->real_escape_string($_POST['tg'])."',
			tn = '".$mysqli->real_escape_string($_POST['tn'])."',
			tnh = '".$mysqli->real_escape_string($_POST['tnh'])."',
			tx = '".$mysqli->real_escape_string($_POST['tx'])."',
			txh = '".$mysqli->real_escape_string($_POST['txh'])."',
			t10n = '".$mysqli->real_escape_string($_POST['t10n'])."',
			t10nh = '".$mysqli->real_escape_string($_POST['t10nh'])."',
			sq = '".$mysqli->real_escape_string($_POST['sq'])."',
			sp = '".$mysqli->real_escape_string($_POST['sp'])."',
			q = '".$mysqli->real_escape_string($_POST['q'])."',
			dr = '".$mysqli->real_escape_string($_POST['dr'])."',
			rh = '".$mysqli->real_escape_string($_POST['rh'])."',
			rhx = '".$mysqli->real_escape_string($_POST['rhx'])."',
			rhxh = '".$mysqli->real_escape_string($_POST['rhxh'])."',
			pg = '".$mysqli->real_escape_string($_POST['pg'])."',
			px = '".$mysqli->real_escape_string($_POST['px'])."',
			pxh = '".$mysqli->real_escape_string($_POST['pxh'])."',
			pn = '".$mysqli->real_escape_string($_POST['pn'])."',
			pnh = '".$mysqli->real_escape_string($_POST['pnh'])."',
			vvn = '".$mysqli->real_escape_string($_POST['vvn'])."',
			vvnh = '".$mysqli->real_escape_string($_POST['vvnh'])."',
			vvx = '".$mysqli->real_escape_string($_POST['vvx'])."',
			vvxh = '".$mysqli->real_escape_string($_POST['vvxh'])."',
			ng = '".$mysqli->real_escape_string($_POST['ng'])."',
			ug = '".$mysqli->real_escape_string($_POST['ug'])."',
			ux = '".$mysqli->real_escape_string($_POST['ux'])."',
			uxh = '".$mysqli->real_escape_string($_POST['uxh'])."',
			un = '".$mysqli->real_escape_string($_POST['un'])."',
			unh = '".$mysqli->real_escape_string($_POST['unh'])."',
			ev24 = '".$mysqli->real_escape_string($_POST['ev24'])."'
			WHERE yyyymmdd = '".$mysqli->real_escape_string($_POST['datum'])."' AND stn = 260 LIMIT 1";
			$res = $mysqli->query($sql);
			
			if($res) {
				if($mysqli->affected_rows > 0) {
					$notes['success'] = 'Wijzigingen opgeslagen ('.$datumNL.')';
				} else {
					$notes['info'] = 'Geen wijzigingen opgeslagen ('.$datumNL.')';
				}
			} else {
				$notes['error'] = 'Er is een fout opgetreden ('.$datumNL.')';
			}
		} else {
			$notes['error'] = 'Datum is onjuist';
		}
	} else {
		$notes['error'] = 'Datum is leeg of onjuist';
	}
}
?>
<!DOCTYPE HTML>
<html>
<head>
<meta charset="UTF-8">
<title>Update - Daggegevens</title>
<link rel="stylesheet" href="css/style.min.css" type="text/css">
<link rel="stylesheet" href="css/account.min.css" type="text/css">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
<style type="text/css">
</style>
</head>
<body>
<?php
echo '<div id="updateContainer">'.PHP_EOL;

echo '<h2>Update daggegevens</h2>'.PHP_EOL;

if(isset($notes) && count(is_array($notes)) > 0) {
	foreach($notes AS $key=>$note) {
		echo '<div class="'.$key.'box">'.$note.'</div>'.PHP_EOL;
	}
}

$sql = "SELECT MIN(yyyymmdd) AS startDatum, date_format(MIN(yyyymmdd), '%e %b %Y') AS startDatumNL, MAX(yyyymmdd) AS eindDatum, date_format(MAX(yyyymmdd), '%e %b %Y') AS eindDatumNL FROM knmi";
$res = $mysqli->query($sql);

if($res) {
	if($res->num_rows > 0) {	
		$rij = $res->fetch_assoc();
		$_SESSION['startDatum'] = $rij['startDatum'];
		$_SESSION['eindDatum'] = $rij['eindDatum'];

		echo '<form method="POST" name="daggegevens">'.PHP_EOL
		.'<fieldset>'.PHP_EOL
		.'<legend>Datum</legend>'.PHP_EOL
		.'<label for="datum">Kies een datum<br />tussen '.$rij['startDatumNL'].' en '.$rij['eindDatumNL'].'</label> <input onchange="getInfo(this);" type="date" name="datum" id="datum" min="'.$rij['startDatum'].'" max="'.$rij['eindDatum'].'" /> <span id="status"><div class="errorbox">Javascript is mogelijk uitgeschakeld</div></span><br />'.PHP_EOL
		.'<label for="yyyymmdd">Datum</label> <input type="text" name="yyyymmdd" id="yyyymmdd" /><br />'.PHP_EOL
		.'</fieldset>'.PHP_EOL
		.PHP_EOL
		.'<fieldset>'.PHP_EOL
		.'<legend>Wind</legend>'.PHP_EOL
		.'<label for="ddvec">Vectorgemiddelde windrichting in graden</label> <input type="text" name="ddvec" id="ddvec" /><br />'.PHP_EOL
		.'<label for="fhvec">Vectorgemiddelde windsnelheid (in 0.1 m/s)</label> <input type="text" name="fhvec" id="fhvec" /><br />'.PHP_EOL
		.'<label for="fg">Etmaalgemiddelde windsnelheid (in 0.1 m/s)</label> <input type="text" name="fg" id="fg" /><br />'.PHP_EOL
		.'<label for="fhx">Hoogste uurgemiddelde windsnelheid (in 0.1 m/s)</label> <input type="text" name="fhx" id="fhx" /><br />'.PHP_EOL
		.'<label for="fhxh">Uurvak waarin FHX is gemeten</label> <input type="text" name="fhxh" id="fhxh" /><br />'.PHP_EOL
		.'<label for="fhn">Laagste uurgemiddelde windsnelheid (in 0.1 m/s)</label> <input type="text" name="fhn" id="fhn" /><br />'.PHP_EOL
		.'<label for="fhnh">Uurvak waarin FHN is gemeten</label> <input type="text" name="fhnh" id="fhnh" /><br />'.PHP_EOL
		.'<label for="fxx">Hoogste windstoot (in 0.1 m/s)</label> <input type="text" name="fxx" id="fxx" /><br />'.PHP_EOL
		.'<label for="fxxh">Uurvak waarin FXX is gemeten</label> <input type="text" name="fxxh" id="fxxh" /><br />'.PHP_EOL
		.'</fieldset>'.PHP_EOL
		.PHP_EOL
		.'<fieldset>'.PHP_EOL
		.'<legend>Temperatuur</legend>'.PHP_EOL
		.'<label for="tg">Etmaalgemiddelde temperatuur (in 0.1 graden Celsius)</label> <input type="text" name="tg" id="tg" /><br />'.PHP_EOL
		.'<label for="tn">Minimum temperatuur (in 0.1 graden Celsius)</label> <input type="text" name="tn" id="tn" /><br />'.PHP_EOL
		.'<label for="tnh">Uurvak waarin TN is gemeten</label> <input type="text" name="tnh" id="tnh" /><br />'.PHP_EOL
		.'<label for="tx">Maximum temperatuur (in 0.1 graden Celsius)</label> <input type="text" name="tx" id="tx" /><br />'.PHP_EOL
		.'<label for="txh">Uurvak waarin TX is gemeten</label> <input type="text" name="txh" id="txh" /><br />'.PHP_EOL
		.'<label for="t10n">Minimum temperatuur op 10 cm hoogte</label> <input type="text" name="t10n" id="t10n" /><br />'.PHP_EOL
		.'<label for="t10nh">6-uurs tijdvak waarin T10N is gemeten</label> <input type="text" name="t10nh" id="t10nh" /><br />'.PHP_EOL
		.'</fieldset>'.PHP_EOL
		.PHP_EOL
		.'<fieldset>'.PHP_EOL
		.'<legend>Zon</legend>'.PHP_EOL
		.'<label for="sq">Zonneschijnduur (in 0.1 uur) berekend uit de globale straling (-1 voor <0.05 uur)</label> <input type="text" name="sq" id="sq" /><br />'.PHP_EOL
		.'<label for="sp">Percentage van de langst mogelijke zonneschijnduur</label> <input type="text" name="sp" id="sp" /><br />'.PHP_EOL
		.'<label for="q">Globale straling (in J/cm2)</label> <input type="text" name="q" id="q" /><br />'.PHP_EOL
		.'</fieldset>'.PHP_EOL
		.PHP_EOL
		.'<fieldset>'.PHP_EOL
		.'<legend>Neerslag</legend>'.PHP_EOL
		.'<label for="dr">Duur van de neerslag (in 0.1 uur)</label> <input type="text" name="dr" id="dr" /><br />'.PHP_EOL
		.'<label for="rh">Etmaalsom van de neerslag (in 0.1 mm) (-1 voor <0.05 mm)</label> <input type="text" name="rh" id="rh" /><br />'.PHP_EOL
		.'<label for="rhx">Hoogste uursom van de neerslag (in 0.1 mm) (-1 voor <0.05 mm)</label> <input type="text" name="rhx" id="rhx" /><br />'.PHP_EOL
		.'<label for="rhxh">Uurvak waarin RHX is gemeten</label> <input type="text" name="rhxh" id="rhxh" /><br />'.PHP_EOL
		.'</fieldset>'.PHP_EOL
		.PHP_EOL
		.'<fieldset>'.PHP_EOL
		.'<legend>Luchtdruk</legend>'.PHP_EOL
		.'<label for="pg">Etmaalgemiddelde luchtdruk herleid tot zeeniveau (in 0.1 hPa) berekend uit 24 uurwaarden</label> <input type="text" name="pg" id="pg" /><br />'.PHP_EOL
		.'<label for="px">Hoogste uurwaarde van de luchtdruk herleid tot zeeniveau (in 0.1 hPa)</label> <input type="text" name="px" id="px" /><br />'.PHP_EOL
		.'<label for="pxh">Uurvak waarin PX is gemeten</label> <input type="text" name="pxh" id="pxh" /><br />'.PHP_EOL
		.'<label for="pn">Laagste uurwaarde van de luchtdruk herleid tot zeeniveau (in 0.1 hPa)</label> <input type="text" name="pn" id="pn" /><br />'.PHP_EOL
		.'<label for="pnh">Uurvak waarin PN is gemeten</label> <input type="text" name="pnh" id="pnh" /><br />'.PHP_EOL
		.'</fieldset>'.PHP_EOL
		.PHP_EOL
		.'<fieldset>'.PHP_EOL
		.'<legend>Zicht</legend>'.PHP_EOL
		.'<label for="vvn">Minimum opgetreden zicht</label> <input type="text" name="vvn" id="vvn" /><br />'.PHP_EOL
		.'<label for="vvnh">Uurvak waarin VVN is gemeten</label> <input type="text" name="vvnh" id="vvnh" /><br />'.PHP_EOL
		.'<label for="vvx">Maximum opgetreden zicht</label> <input type="text" name="vvx" id="vvx" /><br />'.PHP_EOL
		.'<label for="vvxh">Uurvak waarin VVX is gemeten</label> <input type="text" name="vvxh" id="vvxh" /><br />'.PHP_EOL
		.'</fieldset>'.PHP_EOL
		.PHP_EOL
		.'<fieldset>'.PHP_EOL
		.'<legend>Bewolking</legend>'.PHP_EOL
		.'<label for="ng">Etmaalgemiddelde bewolking (bedekkingsgraad van de bovenlucht in achtsten, 9=bovenlucht onzichtbaar)</label> <input type="text" name="ng" id="ng" /><br />'.PHP_EOL
		.'</fieldset>'.PHP_EOL
		.PHP_EOL
		.'<fieldset>'.PHP_EOL
		.'<legend>Vochtigheid</legend>'.PHP_EOL
		.'<label for="ug">Etmaalgemiddelde relatieve vochtigheid (in procenten)</label> <input type="text" name="ug" id="ug" /><br />'.PHP_EOL
		.'<label for="ux">Maximale relatieve vochtigheid (in procenten)</label> <input type="text" name="ux" id="ux" /><br />'.PHP_EOL
		.'<label for="uxh">Uurvak waarin UX is gemeten</label> <input type="text" name="uxh" id="uxh" /><br />'.PHP_EOL
		.'<label for="un">Minimale relatieve vochtigheid (in procenten)</label> <input type="text" name="un" id="un" /><br />'.PHP_EOL
		.'<label for="unh">Uurvak waarin UN is gemeten</label> <input type="text" name="unh" id="unh" /><br />'.PHP_EOL
		.'</fieldset>'.PHP_EOL
		.PHP_EOL
		.'<fieldset>'.PHP_EOL
		.'<legend>Verdamping</legend>'.PHP_EOL
		.'<label for="ev24">Referentiegewasverdamping (Makkink) (in 0.1 mm)</label> <input type="text" name="ev24" id="ev24" /><br />'.PHP_EOL
		.'</fieldset>'.PHP_EOL
		.PHP_EOL
		.'<fieldset>'.PHP_EOL
		.'<legend>Opslaan</legend>'.PHP_EOL
		.'<label></label><button>Opslaan</button>'.PHP_EOL
		.'</fieldset>'.PHP_EOL
		.'</form>'.PHP_EOL;
	} else {
		echo '<div class="infobox">Er is geen data gevonden</div>'.PHP_EOL;
	}
} else {
	echo '<div class="errorbox">Er is een fout opgetreden</div>'.PHP_EOL;
	if(DEBUG) { echo $mysqli->error; }
}
$mysqli->close();
?>
</div>
<script>
$(document).ready(function() {
	$('#status').html('');
	$('input[type="text"],button').prop('disabled', true);
	$('#updateContainer').keypress(function(event) {
		if (event.which == 13 ) {
			event.preventDefault(); // 'Enter' opvangen om POST te voorkomen
		}
	});
});
function getInfo(str) {
	$('input[type="text"],button').prop('disabled', true);
	$('#status').html('<img src="img/load.gif" alt="Data ophalen" />Data ophalen').removeClass('error');
	$(document).ready(function() {
		$.getJSON('json.php?datum='+document.getElementById('datum').value, function(data) {
			if(typeof(data.error) != "undefined" && data.error.length > 0)
			{
				$('#status').html(data.error).addClass('error');
				$('input[type="text"]').val('');
			}
			else
			{
				$('input,button').prop('disabled', false);
				document.daggegevens.yyyymmdd.value = data.yyyymmdd;
				document.daggegevens.ddvec.value = data.ddvec;
				document.daggegevens.fhvec.value = data.fhvec;
				document.daggegevens.fg.value = data.fg;
				document.daggegevens.fhx.value = data.fhx;
				document.daggegevens.fhxh.value = data.fhxh;
				document.daggegevens.fhn.value = data.fhn;
				document.daggegevens.fhnh.value = data.fhnh;
				document.daggegevens.fxx.value = data.fxx;
				document.daggegevens.fxxh.value = data.fxxh;
				document.daggegevens.tg.value = data.tg;
				document.daggegevens.tn.value = data.tn;
				document.daggegevens.tnh.value = data.tnh;
				document.daggegevens.tx.value = data.tx;
				document.daggegevens.txh.value = data.txh;
				document.daggegevens.t10n.value = data.t10n;
				document.daggegevens.t10nh.value = data.t10nh;
				document.daggegevens.sq.value = data.sq;
				document.daggegevens.sp.value = data.sp;
				document.daggegevens.q.value = data.q;
				document.daggegevens.dr.value = data.dr;
				document.daggegevens.rh.value = data.rh;
				document.daggegevens.rhx.value = data.rhx;
				document.daggegevens.rhxh.value = data.rhxh;
				document.daggegevens.pg.value = data.pg;
				document.daggegevens.px.value = data.px;
				document.daggegevens.pxh.value = data.pxh;
				document.daggegevens.pn.value = data.pn;
				document.daggegevens.pnh.value = data.pnh;
				document.daggegevens.vvn.value = data.vvn;
				document.daggegevens.vvnh.value = data.vvnh;
				document.daggegevens.vvx.value = data.vvx;
				document.daggegevens.vvxh.value = data.vvxh;
				document.daggegevens.ng.value = data.ng;
				document.daggegevens.ug.value = data.ug;
				document.daggegevens.ux.value = data.ux;
				document.daggegevens.uxh.value = data.uxh;
				document.daggegevens.un.value = data.un;
				document.daggegevens.unh.value = data.unh;
				document.daggegevens.ev24.value = data.ev24;
				$('#status').html('');
			}
		});
	});
};
</script>
</body>
</html>
