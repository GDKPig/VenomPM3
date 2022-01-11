<?php
/**
 * Created by PhpStorm.
 * User: jkorn2324
 * Date: 2019-04-18
 * Time: 09:20
 */

declare(strict_types=1);

namespace practice\player;


use jojoe77777\FormAPI\SimpleForm;
use pocketmine\entity\Attribute;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityIds;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\form\Form;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use practice\arenas\FFAArena;
use practice\arenas\PracticeArena;
use practice\duels\groups\DuelGroup;
use practice\duels\misc\DuelInvInfo;
use practice\game\entity\FishingHook;
use practice\game\FormUtil;
use practice\player\disguise\DisguiseInfo;
use practice\PracticeCore;
use practice\PracticeUtil;
use practice\scoreboard\Scoreboard;
use practice\scoreboard\ScoreboardUtil;

class PracticePlayer
{
    public const MAX_COMBAT_TICKS = 10;
    public const MAX_ENDERPEARL_SECONDS = 15;

    //BOOLEANS
    private $inCombat;
    private $canThrowPearl;
    private $hasKit;
    private $antiSpam;
    private $canHitPlayer;
    private $isLookingAtForm;
    private $invId;

    //STRINGS
    private $playerName;
    private $currentName;
    private $currentArena;
    private $deviceId;
    private $deviceModel;

    //INTEGERS
    private $currentSec;
    private $antiSendSecs;
    private $lastSecHit;
    private $combatSecs;
    private $enderpearlSecs;
    private $antiSpamSecs;
    private $deviceOs;
    private $input;
    private $duelSpamSec;
    private $noDamageTick;
    private $lastMicroTimeHit;
    private $cid;

    //ARRAYS

    private $currentFormData;

    private $cps = [];

    //OTHER
    private $fishing;
    private $duelResultInvs;

    /* @var Scoreboard */
    private $scoreboard;

    private $scoreboardType;

    private $scoreboardNames;

    private $enderpearlThrows;

    /* @var \pocketmine\entity\Skin|null */
    //private $originalSkin;

    /* @var DisguiseInfo|null */
    //private $disguise;

    /**
     * PracticePlayer constructor.
     * @param Player $player
     * @param int $deviceOs
     * @param int $input
     * @param string $deviceID
     * @param int $clientID
     * @param string $deviceModel
     */
    public function __construct(Player $player, int $deviceOs, int $input, string $deviceID, int $clientID, string $deviceModel) {

        $this->deviceOs = $deviceOs;

        $this->input = $input;

        $this->deviceId = $deviceID;

        $this->cid = $clientID;

        $this->deviceModel = $deviceModel;

        $this->playerName = $player->getName();

        $this->currentName = $this->playerName;

        $this->inCombat = false;
        $this->canThrowPearl = true;
        $this->hasKit = false;
        $this->antiSpam = false;
        $this->canHitPlayer = false;
        $this->isLookingAtForm = false;

        $this->currentArena = PracticeArena::NO_ARENA;

        $this->currentSec = 0;
        $this->antiSendSecs = 0;
        $this->lastSecHit = 0;
        $this->combatSecs = 0;
        $this->enderpearlSecs = 0;
        $this->antiSpamSecs = 0;
        $this->duelSpamSec = 0;
        $this->noDamageTick = 0;
        $this->invId = -1;
        $this->lastMicroTimeHit = 0;

        $this->scoreboardNames = ScoreboardUtil::getNames();

        $this->currentFormData = [];

        $this->fishing = null;
        $this->duelResultInvs = [];
        $this->enderpearlThrows = [];

        $this->initScoreboard(!PracticeCore::getPlayerHandler()->isScoreboardEnabled($this->playerName));
        //$this->disguise = null;
    }

    /*public function saveOriginalSkin(Player $player) : self {
        $this->originalSkin = $player->getSkin();
        return $this;
    }

    public function hasDisguise() : bool {
        return !is_null($this->disguise);
    }

    public function setDisguise(DisguiseInfo $info) : void {
        $this->disguise = $info;
    }

    public function getDisguise() {
        return $this->disguise;
    }*/

    private function initScoreboard(bool $hide = false) : void {

        $name = PracticeUtil::getName('server-name');
        $this->scoreboardType = 'scoreboard.spawn';
        $this->scoreboard = new Scoreboard($this, $name);
        if($hide === true) $this->hideScoreboard();
        else $this->setSpawnScoreboard(false, false);
    }

    public function hideScoreboard() : void {
        $this->scoreboard->removeScoreboard();
    }

    public function showScoreboard() : void {
        $this->scoreboard->resendScoreboard();
        $this->setSpawnScoreboard(false, false);
    }

    public function getScoreboard() : string {
        return $this->scoreboardType;
    }

    public function setSpawnScoreboard(bool $queue = false, bool $clear = true) : void {

        if($clear === true) $this->scoreboard->clearScoreboard();

        $server = Server::getInstance();

        $onlinePlayers = count($server->getOnlinePlayers());

        $inFights = PracticeCore::getPlayerHandler()->getPlayersInFights();

        $inQueues = PracticeCore::getDuelHandler()->getNumberOfQueuedPlayers();

        $onlineStr = PracticeUtil::str_replace($this->scoreboardNames['online'], ['%num%' => $onlinePlayers, '%max-num%' => $server->getMaxPlayers()]);
        $inFightsStr = PracticeUtil::str_replace($this->scoreboardNames['in-fights'], ['%num%' => $inFights]);
        $inQueuesStr = PracticeUtil::str_replace($this->scoreboardNames['in-queues'], ['%num%' => $inQueues]);

        $arr = [$onlineStr, $inFightsStr, $inQueuesStr];

        if($queue === true) {

            $duelHandler = PracticeCore::getDuelHandler();

            if ($duelHandler->isPlayerInQueue($this->playerName)) {

                $queuePlayer = $duelHandler->getQueuedPlayer($this->playerName);

                $str = ' ' . $queuePlayer->toString();

                $this->scoreboard->addLine(5, $str);

                $arr[] = $str . '   ';
            }
        }

        $compare = PracticeUtil::getLineSeparator($arr);

        $separator = '';
        $separator1 = '';


        $len = strlen($separator);

        $len1 = strlen($compare);

        $compare = substr($compare, 0, $len1 - 1);

        $len1--;

        if($len1 > $len) $separator = $compare;

        if($this->deviceOs === PracticeUtil::WINDOWS_10) $separator .= PracticeUtil::WIN10_ADDED_SEPARATOR;

        $this->scoreboard->addLine(0, ' ' . TextFormat::RED . TextFormat::WHITE . $separator1);

        $this->scoreboard->addLine(1, ' ' . $onlineStr);

        $this->scoreboard->addLine(2, ' ' . $inFightsStr);

        $this->scoreboard->addLine(3, ' ' . $inQueuesStr);

        $this->scoreboard->addLine(4, ' ' . TextFormat::GOLD . TextFormat::WHITE . $separator1);

        if($queue === true)

            $this->scoreboard->addLine(6, ' ' . TextFormat::GREEN . TextFormat::WHITE . $separator1);

        $this->scoreboardType = 'scoreboard.spawn';
    }

    public function setDuelScoreboard(DuelGroup $group) : void {
        $playerHandler = PracticeCore::getPlayerHandler();

        $this->scoreboard->clearScoreboard();

        $opponent = ($group->isPlayer($this->playerName)) ? $group->getOpponent() : $group->getPlayer();

        $name = $opponent->getPlayerName();

        $opponentStr = PracticeUtil::str_replace($this->scoreboardNames['opponent'], ['%player%' => $name]);
        $durationStr = PracticeUtil::str_replace($this->scoreboardNames['duration'], ['%time%' => '00:00']);



        $arr = [$opponentStr, $durationStr];

        $compare = PracticeUtil::getLineSeparator($arr);

        $separator = ' ';
        $separator2 = '';

        $len = strlen($separator);

        $len1 = strlen($compare);

        $compare = substr($compare, 0, $len1 - 1);

        $len1--;

        if($len1 > $len) $separator = $compare;

        if($this->deviceOs === PracticeUtil::WINDOWS_10) $separator .= PracticeUtil::WIN10_ADDED_SEPARATOR;

        $this->scoreboard->addLine(0, ' ' . TextFormat::RED . TextFormat::WHITE . $separator2);

        $this->scoreboard->addLine(1, ' ' . $opponentStr . ' ');

        $this->scoreboard->addLine(2, ' ' . $durationStr . ' ');

        $this->scoreboard->addLine(3, ' ');

        $this->scoreboard->addLine(4, ' §bYour Ping§7:§f ' . $this->getPing() . ' ');

        $this->scoreboard->addLine(5, ' §bTheir Ping§7:§f ' . $opponent->getPing() . ' ');

        $this->scoreboard->addLine(6, ' ' . TextFormat::GREEN . TextFormat::WHITE . $separator2);

        $this->scoreboardType = 'scoreboard.duel';
    }



    public function setBoxingScoreboard(DuelGroup $group) : void {
        $playerHandler = PracticeCore::getPlayerHandler();

        $this->scoreboard->clearScoreboard();

        $opponent = ($group->isPlayer($this->playerName)) ? $group->getOpponent() : $group->getPlayer();

        $name = $opponent->getPlayerName();

        $opponentStr = PracticeUtil::str_replace($this->scoreboardNames['opponent'], ['%player%' => $name]);
        $durationStr = PracticeUtil::str_replace($this->scoreboardNames['duration'], ['%time%' => '00:00']);


        $arr = [$opponentStr, $durationStr];

        $compare = PracticeUtil::getLineSeparator($arr);

        $separator = ' ';
        $separator2 = '';

        $len = strlen($separator);

        $len1 = strlen($compare);

        $compare = substr($compare, 0, $len1 - 1);

        $len1--;

        if($len1 > $len) $separator = $compare;

        if($this->deviceOs === PracticeUtil::WINDOWS_10) $separator .= PracticeUtil::WIN10_ADDED_SEPARATOR;

        $this->scoreboard->addLine(0, ' ' . TextFormat::RED . TextFormat::WHITE . $separator2);

        $this->scoreboard->addLine(1, ' ' . $opponentStr . ' ');

        $this->scoreboard->addLine(3, ' ');

        $this->scoreboard->addLine(4, ' §bHits§7: ');
        $this->scoreboard->addLine(5, ' §aYou§7:§f 0' . '');
        $this->scoreboard->addLine(6, ' §cThem§7:§f 0' . '');
        $this->scoreboard->addLine(7, '   ');
        $this->scoreboard->addLine(8, ' §bYour Ping§7:§f ' . $this->getPing() . ' ');

        $this->scoreboard->addLine(9, ' §bTheir Ping§7:§f ' . $opponent->getPing() . ' ');

        $this->scoreboard->addLine(10, ' ' . TextFormat::GREEN . TextFormat::WHITE . $separator2);

        $this->scoreboardType = 'scoreboard.boxing';
    }

    public function setFFAScoreboard(FFAArena $arena) : void {

        $this->scoreboard->clearScoreboard();

        $playerHandler = PracticeCore::getPlayerHandler();

        $arenaName = $arena->getName();

        $kills = $playerHandler->getKillsOf($this->playerName);

        $deaths = $playerHandler->getDeathsOf($this->playerName);

        if(PracticeUtil::str_contains('FFA', $this->scoreboardNames['arena']) and PracticeUtil::str_contains('FFA', $arenaName))
            $arenaName = PracticeUtil::str_replace($arenaName, ['FFA' => '']);

        $killsStr = PracticeUtil::str_replace($this->scoreboardNames['kills'], ['%num%' => $kills]);
        $deathsStr = PracticeUtil::str_replace($this->scoreboardNames['deaths'], ['%num%' => $deaths]);
        $arenaStr = trim(PracticeUtil::str_replace($this->scoreboardNames['arena'], ['%arena%' => $arenaName]));

        $arr = [$killsStr, $deathsStr, $arenaStr];

        $compare = PracticeUtil::getLineSeparator($arr);

        $separator = '------------------';
        $separator1 = '';

        $len = strlen($separator);

        $len1 = strlen($compare);

        $compare = substr($compare, 0, $len1 - 1);

        $len1--;

        if($len1 > $len) $separator = $compare;

        if($this->deviceOs === PracticeUtil::WINDOWS_10) $separator .= PracticeUtil::WIN10_ADDED_SEPARATOR;

        $this->scoreboard->addLine(0, ' ' . TextFormat::RED . TextFormat::WHITE . $separator1);
        $this->scoreboard->addLine(1, ' ' . $killsStr);
        $this->scoreboard->addLine(2, ' ' . $deathsStr);
        $this->scoreboard->addLine(3, ' §bPing§7:§f ' . $this->getPing() . '');
        $this->scoreboard->addLine(4, ' ');
        $this->scoreboard->addLine(5, ' §bvenompvp.xyz ');
        $this->scoreboard->addLine(6, ' ' . TextFormat::GOLD . TextFormat::WHITE . $separator1);
        $this->scoreboardType = 'scoreboard.ffa';
    }

    public function setSpectatorScoreboard(DuelGroup $group) : void {

        $this->scoreboard->clearScoreboard();

        $duration = $group->getDurationString();

        $queue = $group->queueToString();

        $durationStr = PracticeUtil::str_replace($this->scoreboardNames['duration'], ['%time%' => $duration]);

        $arr = [$durationStr, $queue];

        $compare = PracticeUtil::getLineSeparator($arr);

        $separator = '------------------';
        $separator1 = '';

        $len = strlen($separator);

        $len1 = strlen($compare);

        $compare = substr($compare, 0, $len1 - 1);

        $len1--;

        if($len1 > $len) $separator = $compare;

        if($this->deviceOs === PracticeUtil::WINDOWS_10) $separator .= PracticeUtil::WIN10_ADDED_SEPARATOR;

        $this->scoreboard->addLine(0, ' ' . TextFormat::RED . TextFormat::WHITE . $separator1);

        $this->scoreboard->addLine(1, ' ' . $queue);

        $this->scoreboard->addLine(2, ' ' . $durationStr);

        $this->scoreboard->addLine(3, ' ' . TextFormat::BLACK . TextFormat::WHITE . $separator1);

        $this->scoreboardType = 'scoreboard.spec';
    }

    public function updateLineOfScoreboard(int $id, string $line) : void {

        $this->scoreboard->addLine($id, $line);

    }

    public function addCps(bool $clickedBlock): void
    {

        $microtime = microtime(true);

        $keys = array_keys($this->cps);

        $size = count($keys);

        foreach ($keys as $key) {
            $cps = floatval($key);
            if ($microtime - $cps > 1)
                unset($this->cps[$key]);
        }

        if ($clickedBlock === true and $size > 0) {
            $index = $size - 1;
            $lastKey = $keys[$index];
            $cps = floatval($lastKey);
            if (isset($this->cps[$lastKey])) {
                $val = $this->cps[$lastKey];
                $diff = $microtime - $cps;
                if ($val === true and $diff <= 0.05)
                    unset($this->cps[$lastKey]);
            }
        }

        $this->cps["$microtime"] = $clickedBlock;

        $yourCPS = count($this->cps);

        $yourCPSStr = PracticeUtil::str_replace($this->scoreboardNames['player-cps'], ['%player%' => 'Your', '%clicks%' => $yourCPS]);

        if ($this->scoreboardType === 'scoreboard.duel' and $this->isInDuel()) {

            $duel = PracticeCore::getDuelHandler()->getDuel($this->playerName);

            if ($duel->isDuelRunning() and $duel->arePlayersOnline()) {
                $theirCPSStr = PracticeUtil::str_replace($this->scoreboardNames['opponent-cps'], ['%player%' => 'Their', '%clicks%' => $yourCPS]);
                $other = $duel->isPlayer($this->playerName) ? $duel->getOpponent() : $duel->getPlayer();
                $p = $this->getPlayer();


            }
        } elseif ($this->scoreboardType === 'scoreboard.boxing' and $this->isInDuel()) {

            $duel = PracticeCore::getDuelHandler()->getDuel($this->playerName);

            if ($duel->isDuelRunning() and $duel->arePlayersOnline()) {
                $theirCPSStr = PracticeUtil::str_replace($this->scoreboardNames['opponent-cps'], ['%player%' => 'Their', '%clicks%' => $yourCPS]);
                $other = $duel->isPlayer($this->playerName) ? $duel->getOpponent() : $duel->getPlayer();
                $p = $this->getPlayer();
                //$this->updateLineOfScoreboard(4, ' §aYou§7:§f ' . '');
                //$this->updateLineOfScoreboard(5, ' §cThem§7:§f ' . '');
                //$other->updateLineOfScoreboard(4, ' §aYou§7:§f ' . '');
                //$other->updateLineOfScoreboard(5, ' §cThem§7:§f ' . '');
            }
        }
    }

    public function setNoDamageTicks(int $del) : void {
        $this->noDamageTick = $del;
    }

    public function getNoDamageTicks() : int {
        return $this->noDamageTick;
    }

    public function updatePlayer() : void {

        $this->currentSec++;

        if($this->isOnline() and !$this->isInArena()) {

            $p = $this->getPlayer();
            $level = $p->getLevel();

            if($this->currentSec % 5 === 0) {

                $resetHunger = PracticeUtil::areLevelsEqual($level, PracticeUtil::getDefaultLevel());

                if ($resetHunger === false and $this->isInDuel()) {
                    $duel = PracticeCore::getDuelHandler()->getDuel($this->playerName);
                    $resetHunger = PracticeUtil::equals_string($duel->getQueue(), 'Sumo', 'SumoPvP', 'sumo');
                }

                if ($resetHunger === true) {
                    $p->setFood($p->getMaxFood());
                    $p->setSaturation(Attribute::getAttribute(Attribute::SATURATION)->getMaxValue());
                }
            }
        }

        if(PracticeUtil::isEnderpearlCooldownEnabled()) {
            if(!$this->canThrowPearl()) {
                $this->removeSecInThrow();
                if($this->enderpearlSecs <= 0)
                    $this->setThrowPearl(true);
            }
        }

        if($this->isInAntiSpam()){
            $this->antiSpamSecs--;
            if($this->antiSpamSecs <= 0) $this->setInAntiSpam(false);
        }

        if($this->isInCombat()){
            $this->combatSecs--;
            if($this->combatSecs <= 0){
                $this->setInCombat(false);
            }
        }

        if($this->canSendDuelRequest() !== true) $this->duelSpamSec--;
    }

    public function updateNoDmgTicks() : void {
        if($this->noDamageTick > 0) {
            $this->noDamageTick--;
            if($this->noDamageTick <= 0)
                $this->noDamageTick = 0;
        }
    }

    public function setCantSpamDuel() : void {
        //$this->duelSpamTick = PracticeUtil::ticksToSeconds(20);
        $this->duelSpamSec = 20;
    }

    public function getCantDuelSpamSecs() : int {
        return $this->duelSpamSec;
    }

    public function canSendDuelRequest() : bool {
        return $this->duelSpamSec <= 0;
    }

    public function hasDuelInvs() : bool {
        return count($this->duelResultInvs) > 0;
    }

    public function hasInfoOfLastDuel() : bool {
        return $this->hasDuelInvs() and count($this->getInfoOfLastDuel()) > 0;
    }

    public function getInfoOfLastDuel() : array {

        $count = count($this->duelResultInvs);

        return ($count > 0) ? $this->duelResultInvs[$count - 1] : [];
    }

    public function addToDuelHistory(DuelInvInfo $player, DuelInvInfo $opponent) : void {
        $this->duelResultInvs[] = ['player' => $player, 'opponent' => $opponent];
    }

    public function isDuelHistoryItem(Item $item) : bool {

        $result = false;

        if($this->hasInfoOfLastDuel()) {

            $pInfo = $this->getInfoOfLastDuel()['player'];
            $oInfo = $this->getInfoOfLastDuel()['opponent'];

            if($pInfo instanceof DuelInvInfo and $oInfo instanceof DuelInvInfo)
                $result = ($pInfo->getItem()->equalsExact($item) or $oInfo->getItem()->equalsExact($item));
        }
        return $result;
    }

    public function spawnResInvItems() : void {

        if($this->isOnline()) {

            $inv = $this->getPlayer()->getInventory();

            if ($this->hasInfoOfLastDuel()) {

                $res = $this->getInfoOfLastDuel();

                $p = $res['player'];
                $o = $res['opponent'];

                if ($p instanceof DuelInvInfo and $o instanceof DuelInvInfo) {

                    $inv->clearAll();

                    $exitItem = PracticeCore::getItemHandler()->getExitInventoryItem();

                    $slot = $exitItem->getSlot();

                    $item = $exitItem->getItem();

                    $inv->setItem(0, $p->getItem());

                    $inv->setItem(1, $o->getItem());

                    $inv->setItem($slot, $item);
                }

            } else $this->sendMessage(PracticeUtil::getMessage('view-res-inv-msg'));

        }
    }

    public function startFishing() : void {

        if($this->isOnline()) {

            $player = $this->getPlayer();

            if($player !== null and !$this->isFishing()) {

                $tag = Entity::createBaseNBT($player->add(0.0, $player->getEyeHeight(), 0.0), $player->getDirectionVector(), floatval($player->yaw), floatval($player->pitch));
                $rod = Entity::createEntity('FishingHook', $player->getLevel(), $tag, $player);

                if ($rod !== null) {
                    $x = -sin(deg2rad($player->yaw)) * cos(deg2rad($player->pitch));
                    $y = -sin(deg2rad($player->pitch));
                    $z = cos(deg2rad($player->yaw)) * cos(deg2rad($player->pitch));
                    $rod->setMotion(new Vector3($x, $y, $z));
                }

                //$item->count--;

                if (!is_null($rod) and $rod instanceof FishingHook) {
                    $ev = new ProjectileLaunchEvent($rod);
                    $ev->call();
                    if ($ev->isCancelled()) {
                        $rod->flagForDespawn();
                    } else {
                        $rod->spawnToAll();
                        $this->fishing = $rod;
                        $player->getLevel()->broadcastLevelSoundEvent($player, LevelSoundEventPacket::SOUND_THROW, 0, EntityIds::PLAYER);
                    }
                }
            }
        }
    }

    public function stopFishing(bool $click = true, bool $killEntity = true) : void {

        if($this->isFishing()) {

            if($this->fishing instanceof FishingHook) {
                $rod = $this->fishing;
                if($click === true) {
                    $rod->reelLine();
                } elseif ($rod !== null) {
                    if(!$rod->isClosed() and $killEntity === true) {
                        $rod->kill();
                        $rod->close();
                    }
                }
            }
        }

        $this->fishing = null;
    }

    public function isFishing() : bool {
        return $this->fishing !== null;
    }

    public function isInAntiSpam() : bool {
        return $this->antiSpam;
    }

    public function setInAntiSpam(bool $res) : void {
        $this->antiSpam = $res;
        if($this->antiSpam === true)
            $this->antiSpamSecs = 5;
        else $this->antiSpamSecs = 0;
    }

    public function getCurrentSec() : int { return $this->currentSec; }

    public function isInvisible() : bool {
        return $this->getPlayer()->isInvisible();
    }

    public function setInvisible(bool $res) : void {
        if($this->isOnline()) $this->getPlayer()->setInvisible($res);
    }

    public function setHasKit(bool $res) : void {
        $this->hasKit = $res;
    }

    public function doesHaveKit() : bool { return $this->hasKit; }

    public function getPlayerName() : string { return $this->playerName; }

    public function getPlayer() { return Server::getInstance()->getPlayer($this->playerName); }

    public function isOnline() : bool { return isset($this->playerName) and !is_null($this->getPlayer()) and $this->getPlayer()->isOnline(); }

    public function setInCombat(bool $res) : void {

        if($res === true){
            $this->lastSecHit = $this->currentSec;
            $this->combatSecs = self::MAX_COMBAT_TICKS;
            if($this->isOnline()){
                $p = $this->getPlayer();
                if($this->inCombat === false)
                    $p->sendMessage(PracticeUtil::getMessage('general.combat.combat-place'));
            }
        } else {
            $this->combatSecs = 0;
            if($this->isOnline()){
                $p = $this->getPlayer();
                if($this->inCombat === true)
                    $p->sendMessage(PracticeUtil::getMessage('general.combat.combat-remove'));

            }
        }
        $this->inCombat = $res;
    }

    public function isInCombat() : bool { return $this->inCombat; }

    public function getLastSecInCombat() : int { return $this->lastSecHit; }

    public function trackHit() : void {
        $this->lastMicroTimeHit = microtime(true);
    }

    private function removeSecInThrow() : void {
        $this->enderpearlSecs--;
        $maxSecs = self::MAX_ENDERPEARL_SECONDS;
        $sec = $this->enderpearlSecs;
        if($sec < 0) $sec = 0;
        $percent = floatval($this->enderpearlSecs / $maxSecs);
        if($this->isOnline()){
            $p = $this->getPlayer();
            $p->setXpLevel($sec);
            $p->setXpProgress($percent);
        }
    }

    public function canThrowPearl() : bool {
        return $this->canThrowPearl;
    }

    public function setThrowPearl(bool $res) : void {
        if($res === false){
            $this->enderpearlSecs = self::MAX_ENDERPEARL_SECONDS;
            if($this->isOnline()){
                $p = $this->getPlayer();
                if($this->canThrowPearl === true)
                    $p->sendMessage(PracticeUtil::getMessage('general.enderpearl-cooldown.cooldown-place'));

                $p->setXpProgress(1.0);
                $p->setXpLevel(self::MAX_ENDERPEARL_SECONDS);
            }
        } else {
            $this->enderpearlSecs = 0;
            if($this->isOnline()){
                $p = $this->getPlayer();
                if($this->canThrowPearl === false)
                    $p->sendMessage(PracticeUtil::getMessage('general.enderpearl-cooldown.cooldown-remove'));

                $p->setXpLevel(0);
                $p->setXpProgress(0);
            }
        }
        $this->canThrowPearl = $res;
    }

    public function sendMessage(string $msg) : void {
        if($this->isOnline()){
            $p = $this->getPlayer();
            $p->sendMessage($msg);
        }
    }

    public function isInArena() : bool {
        return $this->currentArena !== PracticeArena::NO_ARENA;
    }

    public function setCurrentArena(string $currentArena): void {
        $this->currentArena = $currentArena;
    }

    public function getCurrentArena() {
        return PracticeCore::getArenaHandler()->getArena($this->currentArena);
    }

    public function getCurrentArenaType() : string {

        $type = PracticeArena::NO_ARENA;

        $arena = $this->getCurrentArena();

        if($this->isInArena() and !is_null($arena))
            $type = $arena->getArenaType();

        return $type;
    }

    public function teleportToFFA(FFAArena $arena) {

        if($this->isOnline()) {

            $player = $this->getPlayer();
            $spawn = $arena->getSpawnPosition();
            $msg = null;

            $duelHandler = PracticeCore::getDuelHandler();

            if($duelHandler->isPlayerInQueue($player))
                $duelHandler->removePlayerFromQueue($player, true);

            if(!is_null($spawn)) {

                PracticeUtil::onChunkGenerated($spawn->level, intval($spawn->x) >> 4, intval($spawn->z) >> 4, function() use($player, $spawn) {
                    $player->teleport($spawn);
                });

                $arenaName = $arena->getName();
                $this->currentArena = $arenaName;

                if($arena->doesHaveKit()) {
                    $kit = $arena->getFirstKit();
                    $kit->giveTo($this, true);
                }

                $this->setCanHitPlayer(true);
                $msg = PracticeUtil::getMessage('general.arena.join');
                $msg = strval(str_replace('%arena-name%', $arenaName, $msg));

                $this->setFFAScoreboard($arena);

            } else {

                $msg = PracticeUtil::getMessage('general.arena.fail');
                $msg = strval(str_replace('%arena-name%', $arena->getName(), $msg));
            }

            if(!is_null($msg)) $player->sendMessage($msg);
        }
    }

    public function canHitPlayer() : bool {
        return $this->canHitPlayer;
    }

    public function setCanHitPlayer(bool $res) : void {
        $p = $this->getPlayer();
        if($this->isOnline()) PracticeUtil::setCanHit($p, $res);
        $this->canHitPlayer = $res;
    }


    public function trackThrow() : void {

        $time = microtime(true);

        $key = "$time";

        $this->enderpearlThrows[$key] = false;
    }

    public function checkSwitching() : void {

        $time = microtime(true);

        $count = count($this->enderpearlThrows);

        $keys = array_keys($this->enderpearlThrows);

        if($count > 0 and $this->isOnline()) {

            $len = $count - 1;

            $lastThrow = floatval($keys[$len]);

            $differenceHitNThrow = abs($time - $lastThrow) + 10;
            $differenceHitNThisHit = abs($time - $this->lastMicroTimeHit);

            $ticks = 0.05 * 11.25;

            $result = $this->lastMicroTimeHit !== 0 and $differenceHitNThrow < $differenceHitNThisHit and $differenceHitNThisHit <= $ticks;

            /*$print = ($result === true) ? "true" : "false";

            $str = "$print : $differenceHitNThisHit : $differenceHitNThrow \n";
            var_dump($str);*/

            if($result === true) $this->enderpearlThrows["$lastThrow"] = true;
        }
    }

    public function isSwitching() : bool {

        $keys = array_keys($this->enderpearlThrows);

        $count = count($this->enderpearlThrows);

        $result = false;

        $time = microtime(true);

        //$difference = 0;

        if($count > 0) {

            $len = $count - 1;

            $key = $keys[$len];

            $lastMicroTime = floatval($key);

            $result = boolval($this->enderpearlThrows[$key]);

            if($result === true) {

                $ticks = 0.05 * 12.5;

                $difference = abs($time - $lastMicroTime);

                $result = $difference <= $ticks;
            }
        }

        /*$print = ($result === true ? "true" : "false") . " : $difference";

        var_dump($print);*/

        return $result;
    }

 
    public function getInput() : int {
        return $this->input;
    }

    public function getDevice() : int {
        return $this->deviceOs;
    }

    public function getDeviceID() : string {
        return $this->deviceId;
    }

    public function getCID() : int {
        return $this->cid;
    }

    public function getDeviceToStr(bool $forInfo = false) : string {

        $str = 'Unknown';

        switch($this->deviceOs) {
            case PracticeUtil::ANDROID:
                $str = 'Android';
                break;
            case PracticeUtil::IOS:
                $str = 'iOS';
                break;
            case PracticeUtil::MAC_EDU:
                $str = 'MacOS';
                break;
            case PracticeUtil::FIRE_EDU:
                $str = 'FireOS';
                break;
            case PracticeUtil::GEAR_VR:
                $str = 'GearVR';
                break;
            case PracticeUtil::HOLOLENS_VR:
                $str = 'HoloVR';
                break;
            case PracticeUtil::WINDOWS_10:
                $str = 'Win10';
                break;
            case PracticeUtil::WINDOWS_32:
                $str = 'Win32';
                break;
            case PracticeUtil::DEDICATED:
                $str = 'Dedic.';
                break;
            case PracticeUtil::ORBIS:
                $str = 'Orb';
                break;
            case PracticeUtil::NX:
                $str = 'NX';
                break;
        }

        if($this->input === PracticeUtil::CONTROLS_CONTROLLER and $forInfo === false)
            $str = 'Controller';

        $format = ($forInfo === false) ? TextFormat::WHITE . '[' . TextFormat::GREEN . $str . TextFormat::WHITE . ']' : $str;

        return $format;
    }

    /*public function setInput(int $val) : void {
        if($this->input === -1)
            $this->input = $val;
    }

    public function setDeviceOS(int $val) : void {
        if($this->deviceOs === PracticeUtil::UNKNOWN)
            $this->deviceOs = $val;
    }

    public function setCID(int $cid) : void {
        $this->cid = $cid;
    }

    public function setDeviceID(string $id) : void {
        $this->deviceId = $id;
    }*/

    public function peOnlyQueue() : bool {
        return $this->deviceOs !== PracticeUtil::WINDOWS_10 and $this->input === PracticeUtil::CONTROLS_TOUCH;
    }

    public function isInDuel() : bool {
        return PracticeCore::getDuelHandler()->isInDuel($this->playerName);
    }

    public function isInParty() : bool {
        return PracticeCore::getPartyManager()->isPlayerInParty($this->playerName);
    }

    public function canUseCommands(bool $sendMsg = true) : bool {
        $result = false;
        if($this->isOnline()){
            $msg = null;
            if($this->isInDuel()){
                $msgStr = ($this->isInCombat()) ? 'general.combat.command-msg' : 'general.duels.command-msg';
                $msg = PracticeUtil::getMessage($msgStr);
            } else {
                if($this->isInCombat())
                    $msg = PracticeUtil::getMessage('general.combat.command-msg');
                else $result = true;
            }
            if(!is_null($msg) and $sendMsg) $this->getPlayer()->sendMessage($msg);
        }
        return $result;
    }

    public function getPing() : int {
        $ping = $this->getPlayer()->getPing() - 20;
        if($ping < 0) $ping = 0;
        return $ping;
    }

    public function placeInDuel(DuelGroup $grp) : void {

        if($this->isOnline()) {

            $p = $this->getPlayer();

            $arena = $grp->getArena();

            $isPlayer = $grp->isPlayer($this->playerName);

            $pos = ($isPlayer === true) ? $arena->getPlayerPos() : $arena->getOpponentPos();

            $oppName = ($isPlayer === true) ? $grp->getOpponent()->getPlayerName() : $grp->getPlayer()->getPlayerName();

            $p->setGamemode(0);

            PracticeUtil::onChunkGenerated($pos->level, intval($pos->x) >> 4, intval($pos->z) >> 4, function() use($p, $pos) {
                $p->teleport($pos);
            });

            $queue = $grp->getQueue();

            if($arena->hasKit($queue)){
                $kit = $arena->getKit($queue);
                $kit->giveTo($p);
            }

            $this->setCanHitPlayer(true);

            PracticeUtil::setFrozen($p, true, true);

            $ranked = $grp->isRanked() ? 'Ranked' : 'Unranked';
            $countdown = DuelGroup::MAX_COUNTDOWN_SEC;

            $p->sendMessage(PracticeUtil::str_replace(PracticeUtil::getMessage('duels.start.msg2'), ['%map%' => $grp->getArenaName()]));
            $p->sendMessage(PracticeUtil::str_replace(PracticeUtil::getMessage('duels.start.msg1'), ['%seconds%' => $countdown, '%ranked%' => $ranked, '%queue%' => $queue, '%player%' => $oppName]));
        }
    }

    public function sendForm(Form $form, array $addedContent = []) {

        if($this->isOnline() and !$this->isLookingAtForm) {

            $p = $this->getPlayer();

            $formToJSON = $form->jsonSerialize();

            $content = [];

            if(isset($formToJSON['content']) and is_array($formToJSON['content']))
                $content = $formToJSON['content'];
            elseif (isset($formToJSON['buttons']) and is_array($formToJSON['buttons']))
                $content = $formToJSON['buttons'];

            if(!empty($addedContent))
                $content = array_replace($content, $addedContent);

            $exec = true;

            if($form instanceof SimpleForm) {

                $title = $form->getTitle();

                $ffaTitle = FormUtil::getFFAForm()->getTitle();

                $ranked = null;

                if(isset($addedContent['ranked']))
                    $ranked = boolval($addedContent['ranked']);

                $duelsTitle = FormUtil::getMatchForm()->getTitle();

                if($ranked !== null)
                    $duelsTitle = FormUtil::getMatchForm($ranked)->getTitle();

                $size = -1;

                if($title === $ffaTitle)
                    $size = count(PracticeCore::getArenaHandler()->getFFAArenas());
                elseif ($title === $duelsTitle)
                    $size = count(PracticeCore::getArenaHandler()->getDuelArenas());

                $exec = $size > 0;
            }

            if($exec === true) {

                $this->currentFormData = $content;

                $this->isLookingAtForm = true;

                $p->sendForm($form);

            } else $p->sendForm($form);
        }
    }

    public function removeForm() : array {
        $this->isLookingAtForm = false;
        $data = $this->currentFormData;
        $this->currentFormData = [];
        return $data;
    }

    /**
     * @return array|string[]
     */
    public function getDeviceInfo() : array {

        $title = TextFormat::GOLD . '   » ' . TextFormat::BOLD . TextFormat::BLUE . 'Info of ' . $this->playerName . TextFormat::RESET . TextFormat::GOLD . ' «';

        $deviceOS = TextFormat::GOLD . '   » ' . TextFormat::AQUA . 'Device-OS' . TextFormat::WHITE . ': ' . $this->getDeviceToStr(true) . TextFormat::GOLD . ' «';

        $deviceModel = TextFormat::GOLD . '   » ' . TextFormat::AQUA . 'Device-Model' . TextFormat::WHITE . ': ' . $this->deviceModel . TextFormat::GOLD . ' «';

        $c = 'Unknown';

        switch($this->input) {
            case PracticeUtil::CONTROLS_CONTROLLER:
                $c = 'Controller';
                break;
            case PracticeUtil::CONTROLS_MOUSE:
                $c = 'Mouse';
                break;
            case PracticeUtil::CONTROLS_TOUCH:
                $c = 'Touch';
                break;
        }

        $deviceInput = TextFormat::GOLD . '   » ' . TextFormat::AQUA . 'Device-Input' . TextFormat::WHITE . ': ' . $c . TextFormat::GOLD . ' «';

        $numReports = count(PracticeCore::getReportHandler()->getReportsOf($this->playerName));

        $numOfReports = TextFormat::GOLD . '   » ' . TextFormat::AQUA . 'Times-Reported' . TextFormat::WHITE . ': ' . $numReports . TextFormat::GOLD . ' «';

        $arr = [$title, $deviceOS, $deviceModel, $deviceInput, $numOfReports];

        $lineSeparator = TextFormat::GRAY . PracticeUtil::getLineSeparator($arr);

        return [$title, $lineSeparator, $deviceOS, $deviceModel, $deviceInput, $numOfReports, $lineSeparator];
    }

    public function equals($object) : bool {

        $result = false;

        if($object instanceof PracticePlayer)

            $result = $object->getPlayerName() === $this->playerName;

        return $result;
    }

    /* --------------------------------------------- ANTI CHEAT FUNCTIONS ---------------------------------------------*/

    public function kick(string $msg) : void {
        if($this->isOnline()) {
            $p = $this->getPlayer();
            $p->getInventory()->clearAll();
            $p->kick($msg);
        }
    }
}
