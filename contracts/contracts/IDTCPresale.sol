// SPDX-License-Identifier: MIT
pragma solidity ^0.8.24;

import "@openzeppelin/contracts/access/Ownable.sol";
import "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import "@openzeppelin/contracts/utils/ReentrancyGuard.sol";

/**
 * @title IDTCPresale
 * @notice 3-round presale: Seed ($0.03), Private ($0.04), Public ($0.05).
 * @dev Prices expressed in MATIC wei per 1 IDTC token (18 decimals).
 *      MATIC/USD rate is set at deployment and can be updated by owner.
 *      Buyers receive IDTC immediately on purchase.
 *
 * Security notes:
 *  - ReentrancyGuard on buyTokens and withdraw
 *  - Checks-Effects-Interactions pattern throughout
 *  - Owner cannot rug-pull buyers' already-delivered tokens (tokens sent on purchase)
 */
contract IDTCPresale is Ownable, ReentrancyGuard {
    // ─── Enums & constants ───────────────────────────────────────────────────

    enum Round { NONE, SEED, PRIVATE, PUBLIC }

    // USD price per token in cents (fixed, 18 decimals resolution handled via maticPerUsd)
    // Seed: $0.03 → 3 cents, Private: $0.04 → 4 cents, Public: $0.05 → 5 cents
    uint256 public constant SEED_PRICE_USD_CENTS    = 3;   // $0.03
    uint256 public constant PRIVATE_PRICE_USD_CENTS = 4;   // $0.04
    uint256 public constant PUBLIC_PRICE_USD_CENTS  = 5;   // $0.05

    // Hard caps per round (IDTC tokens with 18 decimals)
    uint256 public constant SEED_CAP    = 10_000_000 * 1e18;   // 10M
    uint256 public constant PRIVATE_CAP = 20_000_000 * 1e18;   // 20M
    uint256 public constant PUBLIC_CAP  = 30_000_000 * 1e18;   // 30M

    // ─── State ───────────────────────────────────────────────────────────────

    IERC20 public immutable idtcToken;

    Round   public currentRound;
    bool    public roundActive;

    // MATIC per 1 USD (18 decimals). E.g. if 1 MATIC = $0.80, maticPerUsd = 1.25e18
    uint256 public maticPerUsd;

    // Tokens sold per round
    uint256 public seedSold;
    uint256 public privateSold;
    uint256 public publicSold;

    // Buyer balances
    mapping(address => uint256) public tokensBought;

    // ─── Events ──────────────────────────────────────────────────────────────

    event RoundStarted(Round indexed round);
    event RoundStopped(Round indexed round);
    event TokensPurchased(address indexed buyer, Round indexed round, uint256 maticPaid, uint256 tokensBought);
    event MaticRateUpdated(uint256 newMaticPerUsd);
    event Withdrawn(address indexed owner, uint256 amount);

    // ─── Constructor ─────────────────────────────────────────────────────────

    /**
     * @param _idtcToken      Address of the deployed IDTC token.
     * @param _initialOwner   Owner/admin address.
     * @param _maticPerUsd    Initial MATIC/USD rate (18 decimals).
     *                        Example: 1 MATIC = $0.80 → pass 1_250_000_000_000_000_000 (1.25e18)
     */
    constructor(
        address _idtcToken,
        address _initialOwner,
        uint256 _maticPerUsd
    ) Ownable(_initialOwner) {
        require(_idtcToken != address(0), "Zero token address");
        require(_maticPerUsd > 0, "Invalid MATIC rate");
        idtcToken = IERC20(_idtcToken);
        maticPerUsd = _maticPerUsd;
        currentRound = Round.NONE;
        roundActive = false;
    }

    // ─── Owner controls ──────────────────────────────────────────────────────

    /**
     * @notice Start a specific round. Stops any previous round.
     */
    function startRound(Round _round) external onlyOwner {
        require(_round != Round.NONE, "Invalid round");
        currentRound = _round;
        roundActive = true;
        emit RoundStarted(_round);
    }

    /**
     * @notice Stop the current active round.
     */
    function stopRound() external onlyOwner {
        require(roundActive, "No active round");
        roundActive = false;
        emit RoundStopped(currentRound);
    }

    /**
     * @notice Update the MATIC/USD rate. Must be called to keep prices accurate.
     * @param _maticPerUsd New rate (18 decimals). E.g. 1 MATIC = $0.80 → 1.25e18
     */
    function setMaticPerUsd(uint256 _maticPerUsd) external onlyOwner {
        require(_maticPerUsd > 0, "Invalid rate");
        maticPerUsd = _maticPerUsd;
        emit MaticRateUpdated(_maticPerUsd);
    }

    // ─── Public purchase ─────────────────────────────────────────────────────

    /**
     * @notice Buy IDTC tokens by sending MATIC.
     * @dev Checks-Effects-Interactions: state updated before token transfer.
     */
    function buyTokens() external payable nonReentrant {
        require(roundActive, "No active round");
        require(msg.value > 0, "Send MATIC to buy");

        uint256 priceCents = _currentPriceCents();
        uint256 cap = _currentCap();

        // tokensOut = (maticPaid * maticPerUsd * 100) / (priceCents * 1e18)
        // Simplified: tokensOut = msg.value * maticPerUsd * 100 / (priceCents * 1e18)
        // All in wei to avoid precision loss
        uint256 tokensOut = (msg.value * maticPerUsd * 100) / (priceCents * 1e18);
        require(tokensOut > 0, "Insufficient MATIC for 1 token");

        // Enforce round cap
        uint256 sold = _currentSold();
        require(sold + tokensOut <= cap, "Exceeds round cap");

        // ── Effects ──────────────────────────────────────────────────────────
        _addSold(tokensOut);
        tokensBought[msg.sender] += tokensOut;

        // ── Interactions ─────────────────────────────────────────────────────
        require(
            idtcToken.transferFrom(owner(), msg.sender, tokensOut),
            "Token transfer failed"
        );

        emit TokensPurchased(msg.sender, currentRound, msg.value, tokensOut);
    }

    /**
     * @notice Withdraw accumulated MATIC to owner.
     */
    function withdraw() external onlyOwner nonReentrant {
        uint256 balance = address(this).balance;
        require(balance > 0, "Nothing to withdraw");
        emit Withdrawn(msg.sender, balance);
        (bool ok, ) = payable(owner()).call{value: balance}("");
        require(ok, "Withdraw failed");
    }

    // ─── Views ───────────────────────────────────────────────────────────────

    /**
     * @notice Returns how many IDTC tokens you get for a given MATIC amount.
     */
    function tokensForMatic(uint256 maticAmount) external view returns (uint256) {
        if (!roundActive || maticAmount == 0) return 0;
        uint256 priceCents = _currentPriceCents();
        return (maticAmount * maticPerUsd * 100) / (priceCents * 1e18);
    }

    /**
     * @notice Returns current round token price in MATIC wei per 1 IDTC (18 dec).
     */
    function currentPriceInMatic() external view returns (uint256) {
        if (!roundActive) return 0;
        uint256 priceCents = _currentPriceCents();
        // price_matic = priceCents / (100 * maticPerUsd) → in wei: priceCents * 1e18 / (100 * maticPerUsd)
        return (priceCents * 1e18) / (100 * maticPerUsd);
    }

    // ─── Internal helpers ────────────────────────────────────────────────────

    function _currentPriceCents() internal view returns (uint256) {
        if (currentRound == Round.SEED)    return SEED_PRICE_USD_CENTS;
        if (currentRound == Round.PRIVATE) return PRIVATE_PRICE_USD_CENTS;
        if (currentRound == Round.PUBLIC)  return PUBLIC_PRICE_USD_CENTS;
        revert("No active round");
    }

    function _currentCap() internal view returns (uint256) {
        if (currentRound == Round.SEED)    return SEED_CAP;
        if (currentRound == Round.PRIVATE) return PRIVATE_CAP;
        if (currentRound == Round.PUBLIC)  return PUBLIC_CAP;
        revert("No active round");
    }

    function _currentSold() internal view returns (uint256) {
        if (currentRound == Round.SEED)    return seedSold;
        if (currentRound == Round.PRIVATE) return privateSold;
        if (currentRound == Round.PUBLIC)  return publicSold;
        revert("No active round");
    }

    function _addSold(uint256 amount) internal {
        if (currentRound == Round.SEED)         seedSold    += amount;
        else if (currentRound == Round.PRIVATE) privateSold += amount;
        else if (currentRound == Round.PUBLIC)  publicSold  += amount;
    }

    // Accept plain MATIC transfers (fallback treated as purchase attempt)
    receive() external payable {
        // Direct MATIC sends must go through buyTokens() for proper accounting
        revert("Use buyTokens()");
    }
}
