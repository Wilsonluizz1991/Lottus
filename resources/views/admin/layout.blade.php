@extends('layouts.app')

@section('content')
<div class="container-fluid py-4">
    <div class="row g-4">
        <div class="col-lg-3 col-xl-2">
            <div class="card border-0 shadow-sm sticky-top" style="top: 20px;">
                <div class="card-body p-3">
                    <div class="mb-3">
                        <div class="small text-uppercase text-muted fw-semibold">Painel</div>
                        <div class="h4 fw-bold mb-0">Lottus Admin</div>
                    </div>

                    <div class="list-group list-group-flush">
                        <a href="{{ route('admin.dashboard') }}"
                           class="list-group-item list-group-item-action rounded-3 mb-2 {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                            Dashboard
                        </a>

                        <a href="{{ route('admin.pedidos.index') }}"
                           class="list-group-item list-group-item-action rounded-3 mb-2 {{ request()->routeIs('admin.pedidos.*') ? 'active' : '' }}">
                            Pedidos
                        </a>

                        <a href="{{ route('admin.cupons.index') }}"
                           class="list-group-item list-group-item-action rounded-3 {{ request()->routeIs('admin.cupons.*') ? 'active' : '' }}">
                            Cupons
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-9 col-xl-10">
            @if(session('success'))
                <div class="alert alert-success shadow-sm border-0 rounded-4">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger shadow-sm border-0 rounded-4">
                    {{ session('error') }}
                </div>
            @endif

            @yield('admin_page')
        </div>
    </div>
</div>
@endsection