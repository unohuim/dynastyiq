# Design Image Generation

## Local Draft Card Preview

Generate the same PHP/GD draft-card image used by Discord announcements:

```bash
php artisan draft:image
```

Output: `docs/designs/draft-card-preview/nathan-aspinall.png`

This local preview does not use OpenAI or send anything to Discord.

## OpenAI Reference Restyle

Generate a modernized draft-card image from the checked-in reference:

```bash
OPENAI_API_KEY=your_api_key npm run design:player-draft-card
```

Input: `docs/designs/player_draft_card.png`

Output: `docs/designs/player_draft_card_modern.png`
