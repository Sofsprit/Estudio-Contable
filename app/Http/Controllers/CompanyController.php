<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
  public function store(Request $request)
  {
    $validated = $request->validate([
      'name' => 'required|string',
      'user' => 'required|string',
      'password' => 'required|string',
      'company_number' => 'required|string',
      'gns_company_name' => 'nullable|string',
    ]);

    //$validated['password'] = Crypt::encryptString($validated['password']);

    $company = Company::create($validated);

    return response()->json([
      'message' => 'Empresa creada correctamente',
      'data' => $company
    ]);
  }

  public function index()
  {
    $companies = Company::all();

    return response()->json($companies);
  }

  public function show($id)
  {
    $company = Company::findOrFail($id);

    return response()->json($company);
  }

  public function update(Request $request, $id)
  {
    $validated = $request->validate([
      'name' => 'sometimes|string',
      'username' => 'sometimes|string',
      'password' => 'sometimes|string',
      'company_number' => 'sometimes|string',
      'gns_company_name' => 'nullable|string',
    ]);

    $company = Company::findOrFail($id);
    $company->update($validated);

    return response()->json(['message' => 'Empresa actualizada correctamente', 'data' => $company]);
  }

  public function destroy($id)
  {
    $company = Company::findOrFail($id);
    $company->delete();

    return response()->json(['message' => 'Empresa eliminada correctamente']);
  }
}
