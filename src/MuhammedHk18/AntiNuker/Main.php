<?php

namespace MuhammedHk18\AntiNuker;

use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginException;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener{

    public static array $warns = [];
    public array $blockBreaks = [];
    public array $lastWarnTime = [];

    public Config $config;

    public function onEnable(): void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveResource("settings.yml");
        $this->config = new Config($this->getDataFolder()."settings.yml", Config::YAML, [
            "harder-breaking-blocks-ids" => [49],
            "middle-breaking-block-ids" => [17],
            "quick-breaking-blocks-ids" => [1, 3, 12, 15, 21, 22, 48, 42, 56, 57, 133, 129, 16, 14, 41],
            "quick-breaking-block-count-per-5-second" => 40,
            "middle-breaking-block-count-per-5-second" => 30,
            "harder-breaking-block-count-per-5-second" => 20,
            "warn-count" => 5,
            "warn-type" => "ban",
            "allowed-worlds" => ["world"],
            "console-logging-warn" => "{%0} player hacking",
            "warn-message" => "Please stop using hacks.",
            "ban-reason" => "You were banned by AntiNuker",
            "kick-reason" => "You were kicked by AntiNuker",
            "warn-expire-time" => 300 // 5 minutes in seconds
        ]);
    }

    public function isHaveWarn(Player|string $player): bool
    {
        if($player instanceof Player) $player = $player->getName();
        return isset(self::$warns[$player]);
    }

    public function getPlayerWarn(Player|string $player): int
    {
        if($player instanceof Player) $player = $player->getName();
        return self::$warns[$player] ?? 0;
    }

    public function addWarn(Player|string $player): void
    {
        if($player instanceof Player) $player = $player->getName();

        self::$warns[$player] = $this->getPlayerWarn($player) + 1;
        $this->lastWarnTime[$player] = time();
    }

    public function reduceWarn(Player|string $player): void
    {
        if($player instanceof Player) $player = $player->getName();

        if($this->getPlayerWarn($player) <= 1){
            unset(self::$warns[$player]);
            unset($this->lastWarnTime[$player]);
        }else{
            self::$warns[$player]--;
        }
    }

    public function checkAndExpireWarns(Player|string $player): void
    {
        if($player instanceof Player) $player = $player->getName();

        if($this->isHaveWarn($player) &&
            (time() - ($this->lastWarnTime[$player] ?? 0)) > $this->config->get("warn-expire-time", 300)) {
            $this->reduceWarn($player);
        }
    }

    public function getMaxCountForNuke(int $block): int
    {
        $slowids = $this->config->get("harder-breaking-blocks-ids");
        $fastids = $this->config->get("quick-breaking-blocks-ids");
        $middleids = $this->config->get("middle-breaking-block-ids");

        if(in_array($block, (array)$fastids)) return (int)$this->config->get("quick-breaking-block-count-per-5-second");
        if(in_array($block, (array)$slowids)) return (int)$this->config->get("harder-breaking-block-count-per-5-second");
        if(in_array($block, (array)$middleids)) return (int)$this->config->get("middle-breaking-block-count-per-5-second");

        throw new PluginException("Please make sure you have set settings.yml properly.");
    }

    public function onBlockBreak(BlockBreakEvent $event): void
    {
        $player = $event->getPlayer();
        $block = $event->getBlock();

        $worlds = $this->config->get("allowed-worlds");
        if(!in_array($player->getWorld()->getFolderName(), $worlds)) return;

        $this->checkAndExpireWarns($player);

        $countMax = $this->getMaxCountForNuke($block->getTypeId());
        if($countMax <= 0) return;

        $hash = $block->getPosition()->getX() . ":" . $block->getPosition()->getY() . ":" . $block->getPosition()->getZ();
        $now = time();
        $breaks = &$this->blockBreaks[$player->getName()];
        $breaks[$hash] = $now;
        $count = 0;

        foreach($breaks as $h => $time) {
            if($h === $hash) continue;

            if(time() - $time < 5) {
                $count++;

                if($count >= $countMax) {
                    if($this->config->get("warn-message")) {
                        $warnCount = $this->getPlayerWarn($player);
                        $player->sendMessage(
                            $this->config->get("warn-message") .
                            " (Warnings: " . ($warnCount + 1) . "/" . $this->config->get("warn-count") . ")"
                        );
                    }

                    $this->addWarn($player);

                    if($this->config->get("console-logging-warn")) {
                        $msg = str_replace("{%0}", $player->getName(), $this->config->get("console-logging-warn"));
                        $this->getServer()->getLogger()->warning($msg . " Total warns: " . $this->getPlayerWarn($player));
                    }

                    if($this->getPlayerWarn($player) >= $this->config->get("warn-count")) {
                        $this->punishPlayer($player);
                        $event->cancel();
                        return;
                    }

                    unset($breaks[$h]);
                    $event->cancel();
                    return;
                }
            } else {
                unset($breaks[$h]);
            }
        }
    }

    protected function punishPlayer(Player $player): void
    {
        $warnType = $this->config->get("warn-type");

        switch($warnType) {
            case "kick":
                $player->kick($this->config->get("kick-reason"));
                break;

            case "ban":
                $this->getServer()->getNameBans()->addBan(
                    $player->getName(),
                    $this->config->get("ban-reason"),
                    null,
                    "AntiNuker"
                );
                $player->kick($this->config->get("ban-reason"));
                break;

            default:
                self::$warns[$player->getName()] = 0;
                unset($this->lastWarnTime[$player->getName()]);
                break;
        }
    }
}
