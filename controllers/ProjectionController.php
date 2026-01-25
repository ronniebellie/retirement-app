<?php

require_once __DIR__ . '/../models/ProjectionModel.php';

class ProjectionController
{
    public function index()
    {
        $model = new ProjectionModel();

        $start = isset($_GET['start']) ? (float)$_GET['start'] : 1375054.0;
        $rate  = isset($_GET['rate'])  ? (float)$_GET['rate']  : 0.08;
        $years = isset($_POST['years']) ? (int) $_POST['years'] : 10;

        $startYear = isset($_GET['startYear']) ? (int)$_GET['startYear'] : 2027;
        $years     = isset($_GET['years'])     ? (int)$_GET['years']     : 10;

        $rows = $model->projectYears($start, $rate, $startYear, $years);
        require __DIR__ . '/../views/projection.php';
    }
}