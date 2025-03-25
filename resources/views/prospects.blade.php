<div>
    <!-- The whole future lies in uncertainty: live immediately. - Seneca -->


    <table>
        <th>Prospect</th><th>League</th><th>Team</th>
        <th>GP</th><th>G</th><th>A</th><th>PTS</th>

        @foreach ($stats as $stat)
        <tr>
            <td>{{ $stat->player_name}}</td><td>{{$stat->league_abbrev}}</td><td>{{$stat->team_name}}</td>
            <td>{{ $stat->GP}}</td><td>{{$stat->G}}</td><td>{{$stat->A}}</td><td>{{$stat->PTS}}</td>
        </tr>

        @endforeach
    </table>
</div>
