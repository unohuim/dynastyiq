<x-app-layout>
	<div class="p-1 px-4">
		@foreach($leagues as $league)

			<div class="px-2 py-2 bg-indigo-100 text-gray-500 border-b border-gray-300">
				<span>
					{{$league->league_name}}
				</span>
			</div>

		@endforeach
	</div>	
</x-app-layout>
