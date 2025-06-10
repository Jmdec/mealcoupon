<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    // Get all employees
    public function index()
    {
        $employees = Employee::all();
        return response()->json($employees);
    }

    // Get total count of employees
    public function getTotalCount()
    {
        return response()->json(['total' => Employee::count()]);
    }

    // Store a new employee
    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|unique:employees,employee_id',
            'first_name'  => 'required|string|max:255',
            'last_name'   => 'required|string|max:255',
            'email'       => 'required|email|unique:employees,email',
            'department'  => 'required|string|max:255',
        ]);

        Employee::create($validated);

        return response()->json(['message' => 'Employee added successfully'], 201);
    }

    // Update an existing employee
    public function update(Request $request, $id)
    {
        $employee = Employee::findOrFail($id);  // Use internal id

        $validated = $request->validate([
            'employee_id' => 'required|unique:employees,employee_id,' . $employee->id . ',id',
            'first_name'  => 'required|string|max:255',
            'last_name'   => 'required|string|max:255',
            'email'       => 'required|email|unique:employees,email,' . $employee->id . ',id',
            'department'  => 'required|string|max:255',
        ]);

        $employee->update($validated);

        return response()->json($employee);
    }

    // Delete an employee
    public function destroy($id)
    {
        $employee = Employee::where('id', $id)->firstOrFail();
        $employee->delete();

        return response()->json(['message' => 'Employee deleted successfully']);
    }
}
