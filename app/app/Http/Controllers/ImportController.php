<?php

namespace App\Http\Controllers;

use App\Services\StudentImporter;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function form()
    {
        return view('import');
    }

    public function run(Request $request, StudentImporter $importer)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            'year' => 'nullable|integer|min:2020|max:2099',
        ]);

        $path = $request->file('file')->getRealPath();
        $year = (int) ($request->input('year') ?: date('Y'));

        try {
            $stats = $importer->import($path, $year);
        } catch (\Throwable $e) {
            return back()->with('flash', __('flash.import_failed') . ' ' . $e->getMessage())->with('flash_type', 'error');
        }

        $msg = __('flash.import_success', [
            'students' => $stats['students_created'] . '/' . $stats['students_updated'],
            'payments' => $stats['payments_created'],
        ])
            . ' · ' . __('family.title') . ': +' . $stats['families_created']
            . ' · X: ' . $stats['markers_created']
            . ' · ✗ ' . $stats['phones_invalid'];

        return redirect()->route('home')->with('flash', $msg)->with('flash_type', 'success');
    }
}
