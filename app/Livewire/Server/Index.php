<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

class Index extends Component
{
    public ?Collection $servers = null;

    public function mount()
    {
        $this->servers = Server::visibleDeploymentServersForTeam(currentTeam()->id)
            ->with(['settings', 'team'])
            ->get();
    }

    public function render()
    {
        return view('livewire.server.index');
    }
}
