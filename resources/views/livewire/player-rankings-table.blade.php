<div>
    
    <div class="bg-white rounded shadow p-4">
	    <div class="mb-4">
	        <label class="text-sm text-gray-600 font-semibold mr-2">Profile:</label>
	        <select class="border rounded px-3 py-1 text-sm">
	            <option value="FHL 2025">FHL 2025</option>
	            
	            <!-- Add more types as needed -->
	        </select>
	    </div>

	    <div class="overflow-auto">
	        <table class="min-w-full table-auto text-left">
			    <thead class="bg-gray-50 text-xs font-semibold uppercase text-gray-600">
			        <tr>
			            <th class="px-4 py-2">Player</th>
			            <th class="px-4 py-2">Latest Rank</th>
			            <th class="px-4 py-2">Previous #1</th>
			            <th class="px-4 py-2">Previous #2</th>
			        </tr>
			    </thead>
			    <tbody class="text-sm text-gray-700 divide-y divide-gray-100">
			        @foreach ($players as $player)
			            <tr>
			                <td class="px-4 py-2 font-medium">
			                    @if(isset($player->full_name))
			                        {{ $player->full_name }}
			                    @else
			                        {{ $player->first_name . " " . $player->last_name }}
			                    @endif
			                </td>

			                
			                {{-- Latest Rank Column --}}
							<td class="px-4 py-2">
							    <div
							    	wire:key="player-{{ $player->id }}-rank-{{ $player->currentRanking->score ?? '' }}"
							        x-data="{
							            editingMode: false,
							            value: '{{ $player->currentRanking->score ?? '' }}',
							            startEdit() { this.editingMode = true; this.$nextTick(() => $refs.input.focus()) },
							            saved: false,
							            timeout: null,

							            save() {
										    clearTimeout(this.timeout)
										    this.timeout = setTimeout(() => {
										        this.$wire.save({{ $player->id }}, this.value).then(() => {
										            this.saved = true
										            this.editingMode = false
										            Livewire.emit('reinitializeRow')
										            setTimeout(() => this.saved = false, 2000)
										        })
										    }, 200)
										}
							        }"
							        class="flex items-center space-x-2"
							    >
							        <template x-if="editingMode">
							            <input type="number" step="0.01" inputmode="decimal"
						          				x-model="value"
							                   wire:model.defer="editingValues.{{ $player->id }}.score"
							                   @blur="save()"							                  
							                   @keydown.enter.prevent="save()"
							                   @keydown.tab.prevent="save()"
							                   class="w-20 px-2 py-1 border border-gray-300 rounded text-sm" />
							        </template>

							        <template x-if="!editingMode">
							            <div class="flex items-center space-x-2">
							                <span x-text="value === '' ? '-' : parseFloat(value).toFixed(2)"></span>
							                <button @click="startEdit()"
							                        class="p-1 rounded hover:bg-blue-100 transition"
							                        title="Edit">
							                    <svg xmlns="http://www.w3.org/2000/svg" fill="none"
							                         viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
							                         class="w-4 h-4 text-blue-400 hover:text-blue-600">
							                        <path stroke-linecap="round" stroke-linejoin="round"
							                              d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L19.514 7.14m-2.652-2.652L10.5 10.5m0 0L9 15l4.5-1.5M10.5 10.5L14.25 14.25M21 12.75v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18.75V5.25A2.25 2.25 0 015.25 3h6" />
							                    </svg>
							                </button>
							            </div>
							        </template>

							        <template x-if="saved">
							            <svg xmlns="http://www.w3.org/2000/svg"
							                 fill="none" viewBox="0 0 24 24" stroke-width="2"
							                 stroke="currentColor" class="w-4 h-4 text-green-500">
							                <path stroke-linecap="round" stroke-linejoin="round"
							                      d="M4.5 12.75l6 6 9-13.5" />
							            </svg>
							        </template>
							    </div>
							</td>






			                {{-- Previous Rankings --}}
			                <td class="px-4 py-2">
			                    {{ $player->rankingsForUser[1]->score ?? '-' }}
			                </td>
			                <td class="px-4 py-2">
			                    {{ $player->rankingsForUser[2]->score ?? '-' }}
			                </td>
			            </tr>
			        @endforeach
			    </tbody>
			</table>
	    </div>
	</div>

</div>
