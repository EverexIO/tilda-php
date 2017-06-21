<?php
error_reporting(E_ALL);

define('TILDA_PROJECT_ID', '???');
define('TILDA_PUBLIC_KEY', '???');
define('TILDA_SECRET_KEY', '???');

include ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "classes" . DIRECTORY_SEPARATOR . "Tilda" . DIRECTORY_SEPARATOR . "Api.php";
include ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "classes" . DIRECTORY_SEPARATOR . "Tilda" . DIRECTORY_SEPARATOR . "LocalProject.php";

$htaccessAdd = "";

set_time_limit(0);
header("Cache-Control: no-cache, must-revalidate");
header('X-Accel-Buffering: no');
ob_implicit_flush(true);
while (ob_get_level() > 0) {
    ob_end_flush();
}

echo '<!doctype html><html lang="en"><head><meta charset="UTF-8"><title>Export site</title></head><body>';

showExportForm(TILDA_PROJECT_ID);

if(empty($_POST['action'])){
    echo '</body></html>';
    die;
}

use \Tilda;

$api = new Tilda\Api(TILDA_PUBLIC_KEY, TILDA_SECRET_KEY);

$projectId = TILDA_PROJECT_ID;
$projectTitle = null;
if(isset($_POST['id']) && intval($_POST['id'])){
    $projectId = intval($_POST['id']);
}

$arProjects = $api->getProjectsList();
foreach($arProjects as $ind => $project){
    if($project['id'] == $projectId){
        $projectTitle = $project['title'];
        break;
    }
}

if(!$projectTitle){
    echo '<br><br>Export project with ID ' . $projectId . ' not found!';
    die;
}

echo '<br><br>Export project with ID ' . $projectId . "<br><br>";
flush();
ob_flush();

/* Запрашиваем список страниц проекта и сохраняем ID страниц */
$arExportPages = array();
$arPages = $api->getPagesList($projectId);
if (! $arPages) {
    die("Error in connected to API: " . $api->lastError);
}

/* собираем IDшники страниц */
foreach ($arPages as $arPage) {
    $arExportPages[] = $arPage['id'];
}
unset($arPages);

$aReplceParts = array(
    '"//static.tildacdn.com/js/' => '"',
    '"//static.tildacdn.com/css/' => '"',
    '"http://tilda.ws/js/' => '"',
    '"http://tilda.ws/css/' => '"',
    '"//js/'  => '"/js/',
    '"//css/'  => '"/css/'
);

/* если все таки есть, что экспортировать */
if (sizeof($arExportPages) > 0) {
    $local = new Tilda\LocalProject(array(
            'baseDir'    => '.',
            'projectDir' => '',
            'exportDir'  => $projectId . '-' . preg_replace("/[^A-Za-z0-9]/", '', $projectTitle) . '-' . date("YmdHi")
        )
    );
    /* создаем основные директории проекта (если еще не созданы) */
    if ($local->createBaseFolders() === false) {
        die("Error for create folders<br>");
    }
    
    /*  берем данные по общим файлам проекта */
    $arProject = $api->getProjectExport($projectId);
    if (! $arProject) {
        die('Not found project [' . $api->lastError . ']');
    }
    $local->setProject($arProject);
    
    $arSearchFiles = array();
    $arReplaceFiles = array();

    echo "<pre>";

    /* копируем общие CSS файлы */
    $arFiles = $local->copyCssFiles('css');
    if (! $arFiles) {
        die('Error in copy CSS files [' . $api->lastError . ']');
    }
    print_r($arFiles);
    
    /* копируем общие JS файлы */
    $arFiles = $local->copyJsFiles('js');
    if (! $arFiles) {
        die('Error in copy JS files [' . $api->lastError . ']');
    }
    print_r($arFiles);
    flush();
    ob_flush();

    /* копируем общие IMG файлы */
    $arFiles = $local->copyImagesFiles('img');
    if (! $arFiles) {
        die('Error in copy IMG files [' . $api->lastError . ']');
    }
    print_r($arFiles);
    flush();
    ob_flush();

    $res = $local->createHTAccessFile($htaccessAdd);
    if (! $res) {
        die('Error in creating .htaccess file');
    }
    /* перебеираем теперь страницы и скачиваем каждую по одной */
    $countexport = 1;
    foreach ($arExportPages as $pageid) {
        try {
            echo "Export page " . $pageid . "<br>";

            /* запрашиваем все данные для экспорта страницы */
            $tildapage = $api->getPageFullExport($pageid);
            if (! $tildapage || empty($tildapage['html'])) {
                echo "Error: cannot get page [$pageid] or page not publish<br>";
                continue;
            }

            $tildapage['export_imgpath'] = $local->arProject['export_imgpath'];
            $tildapage['needsync'] = '0';

            /* так как мы копировали общие файлы в одни папки, а в HTML они указывают на другие, то произведем замену */
            $html = preg_replace($local->arSearchFiles, $local->arReplaceFiles, $tildapage['html']);
            foreach($aReplceParts as $searchPart => $replacePart){
                $html = str_replace($searchPart, $replacePart, $html);
            }
            if ($html > '') {
                $tildapage['html'] = $html;
            }

            /* сохраним страницу  (при сохранении также происходит копирование картинок использованных на странице) */
            $tildapage = $local->savePage($tildapage);
            echo "Save page $pageid - success<br>";

            $tildapage = $local->saveMetaPage($tildapage);
        } catch (Exception $e) {
            echo "Error [".$countexport."] tilda page dont export ".$pageid." [".$e->getMessage()."]<br>";
        }
        $countexport++;
        flush();
        ob_flush();
    }

    echo "Fix outer images in all pages:<br>";
    print_r($local->arOuterImageFiles);
    foreach ($arExportPages as $pageid) {
        try {
            $tildapage = $api->getPageFullExport($pageid);
            if (! $tildapage || empty($tildapage['html'])) {
                echo "Error: cannot get page [$pageid] or page not publish<br>";
                continue;
            }

            $local->fixOuterImages($tildapage['filename']);

        } catch (Exception $e) {
            echo "Error on page ".$pageid." [".$e->getMessage()."]<br>";
        }
    }

    $local->archiveSite(true);
    echo "<br>Export project " . $project['title'] . " - success<br>";
    echo '</body></html>';
}

function showExportForm($projectId){
$form = <<<EOT
      <form method="post" action="">
        <input type="hidden" name="action" value="export">
        Project ID: <input type="text" name="id" value="{$projectId}">
        <input type="submit" value="Export">
      </form>
EOT;
    echo $form;
}
