# Teams of {{display}}

{{#if teams.length}}
{{#each teams}}
• {{league_name}} — {{team_name}} - <https://www.fantrax.com/fantasy/league/{{league_id}}/team/roster>
{{/each}}
{{else}}
(no shared leagues)
{{/if}}
