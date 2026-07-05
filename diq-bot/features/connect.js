// features/connect.js
const {
    ActionRowBuilder,
    ButtonBuilder,
    ButtonStyle,
    MessageFlags,
    ModalBuilder,
    SlashCommandBuilder,
    TextInputBuilder,
    TextInputStyle,
} = require("discord.js");
const axios = require("axios");

const COMMAND_NAME = "diq";
const FANTRAX_BUTTON_ID = "diq-connect-fantrax";
const FANTRAX_MODAL_ID = "diq-connect-fantrax-modal";
const FANTRAX_SECRET_INPUT_ID = "fantrax-secret-id";

function getApiBase() {
    return (
        process.env.API_BASE_URL ||
        process.env.DIQ_API_BASE_URL ||
        process.env.APP_URL ||
        ""
    );
}

function getBotApiSecret() {
    return process.env.DIQ_BOT_API_SECRET || process.env.DISCORD_WEBHOOK_SECRET || "";
}

function yahooConnectUrl() {
    const apiBase = getApiBase();
    if (!apiBase) return null;

    const url = new URL("/integrations/yahoo/redirect", apiBase);
    url.searchParams.set("return_to", "/leagues");
    url.searchParams.set("drawer", "account");

    return url.toString();
}

function commandJson() {
    const command = new SlashCommandBuilder()
        .setName(COMMAND_NAME)
        .setDescription("DynastyIQ tools")
        .addSubcommand((subcommand) =>
            subcommand
                .setName("connect")
                .setDescription("Connect your fantasy platform accounts to DynastyIQ")
        )
        .setDMPermission(false)
        .toJSON();

    return {
        ...command,
        integration_types: [0],
        contexts: [0],
    };
}

function connectionPanelComponents() {
    const row = new ActionRowBuilder().addComponents(
        new ButtonBuilder()
            .setCustomId(FANTRAX_BUTTON_ID)
            .setLabel("Connect Fantrax")
            .setStyle(ButtonStyle.Primary)
    );

    const yahooUrl = yahooConnectUrl();
    if (yahooUrl) {
        row.addComponents(
            new ButtonBuilder()
                .setLabel("Connect Yahoo")
                .setStyle(ButtonStyle.Link)
                .setURL(yahooUrl)
        );
    }

    return [row];
}

async function showConnectPanel(interaction) {
    await interaction.reply({
        flags: MessageFlags.Ephemeral,
        content: [
            "**Connect DynastyIQ**",
            "Choose one account to connect. Fantrax opens here; Yahoo continues in your browser.",
        ].join("\n"),
        components: connectionPanelComponents(),
    });
}

async function showFantraxModal(interaction) {
    const modal = new ModalBuilder()
        .setCustomId(FANTRAX_MODAL_ID)
        .setTitle("Connect Fantrax");

    const secretInput = new TextInputBuilder()
        .setCustomId(FANTRAX_SECRET_INPUT_ID)
        .setLabel("Fantrax Secret ID")
        .setStyle(TextInputStyle.Short)
        .setRequired(true)
        .setMaxLength(255)
        .setPlaceholder("Paste your Fantrax user secret ID");

    modal.addComponents(new ActionRowBuilder().addComponents(secretInput));

    await interaction.showModal(modal);
}

async function submitFantraxConnection(interaction) {
    await interaction.deferReply({ flags: MessageFlags.Ephemeral });

    const apiBase = getApiBase();
    const botSecret = getBotApiSecret();

    if (!apiBase || !botSecret) {
        await interaction.editReply("DIQ bot connection is not configured.");
        return true;
    }

    const secretId = interaction.fields.getTextInputValue(FANTRAX_SECRET_INPUT_ID);

    try {
        const response = await axios.post(
            new URL("/api/discord/fantrax/connect", apiBase).toString(),
            {
                discord_user_id: interaction.user.id,
                guild_id: interaction.guildId,
                secret_id: secretId,
            },
            {
                timeout: 15000,
                headers: {
                    "User-Agent": "diq-bot",
                    "X-DIQ-Bot-Secret": botSecret,
                },
                validateStatus: (status) => status >= 200 && status < 500,
            }
        );

        if (!response.data?.ok) {
            await interaction.editReply(response.data?.message || "Fantrax could not be connected.");
            return true;
        }

        const leagueCount = Number(response.data.league_count || 0);
        await interaction.editReply(`Fantrax connected. Found ${leagueCount} league${leagueCount === 1 ? "" : "s"}.`);
    } catch {
        await interaction.editReply("Fantrax could not be connected right now.");
    }

    return true;
}

async function handle(interaction) {
    if (interaction.isChatInputCommand() && interaction.commandName === COMMAND_NAME) {
        if (interaction.options.getSubcommand() !== "connect") return false;

        await showConnectPanel(interaction);
        return true;
    }

    if (interaction.isButton() && interaction.customId === FANTRAX_BUTTON_ID) {
        await showFantraxModal(interaction);
        return true;
    }

    if (interaction.isModalSubmit() && interaction.customId === FANTRAX_MODAL_ID) {
        return submitFantraxConnection(interaction);
    }

    return false;
}

module.exports = {
    commandJson,
    handle,
};
