// SPDX-License-Identifier: MIT
pragma solidity ^0.8.24;

import "@openzeppelin/contracts/access/Ownable.sol";
import "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import "@openzeppelin/contracts/utils/ReentrancyGuard.sol";

/**
 * @title IDTCPresale
 * @notice 3-round presale: Seed ($0.03), Private ($0.04), Public ($0.05).
 *
 * Token allocation per round (matches whitepaper):
 *   Seed:    10,000,000 IDTC cap  (10% of supply)
 *   Private: 15,000,000 IDTC cap  (15% of supply)
 *   Public:  25,000,000 IDTC cap  (25% of supply)
 *   Total presale: 50,000,000 IDTC (50% of supply)
 *
 * Listing price: $0.07 on QuickSwap V3 (Polygon)
 *
 * Security:
 *   - ReentrancyGuard on buyTokens and withdraw
 *   - Checks-Effects-Interactions pattern
 *   - Per-round min/max enforced on-chain
 *   - Owner cannot rug: tokens transferred immediately to buyer on purchase
 *   - Solidity 0.8+ built-in overflow protection
 */
contract IDTCPresale is Ownable, ReentrancyGuard {

    // ─── Enums ────────────────────────────────────────────────────────────────

    enum Round { NONE, SEED, PRIVATE, PUBLIC }

    // ─── Round caps (matches whitepaper tokenomics) ───────────────────────────

    uint256 public constant SEED_CAP    = 10_000_000 * 1e18;  // 10M IDTC
    uint256 public constant PRIVATE_CAP = 15_000_000 * 1e18;  // 15M IDTC
    uint256 public constant PUBLIC_CAP  = 25_000_000 * 1e18;  // 25M IDTC

    // ─── USD price per token (in cents) ───────────────────────────────────────

    uint256 public constant SEED_PRICE_USD_CENTS    = 3;  // $0.03
    uint256 public constant PRIVATE_PRICE_USD_CENTS = 4;  // $0.04
    uint256 public constant PUBLIC_PRICE_USD_CENTS  = 5;  // $0.05

    // ─── Per-round min/max purchase in IDTC (18 decimals) ────────────────────
    // Matches whitepaper sale page specs

    uint256 public constant SEED_MIN    = 1_000   * 1e18;   // 1,000 IDTC minimum
    uint256 public constant SEED_MAX    = 500_000 * 1e18;   // 500,000 IDTC maximum

    uint256 public constant PRIVATE_MIN = 500     * 1e18;   // 500 IDTC minimum
    uint256 public constant PRIVATE_MAX = 250_000 * 1e18;   // 250,000 IDTC maximum

    uint256 public constant PUBLIC_MIN  = 100     * 1e18;   // 100 IDTC minimum
    uint256 public constant PUBLIC_MAX  = 100_000 * 1e18;   // 100,000 IDTC maximum

    // ─── State ────────────────────────────────────────────────────────────────

    IERC20 public immutable idtcToken;

    Round  public currentRound;
    bool   public roundActive;

    /**
     * @notice USD value of 1 MATIC, with 18 decimals.
     *         e.g. 1 MATIC = $0.87 → set to 0.87e18 (870000000000000000)
     *
     * IMPORTANT: This is the MATIC price in USD, NOT MATIC per USD.
     *            If MATIC = $0.87, pass 870000000000000000 (0.87 * 1e18).
     */
    uint256 public usdPerMatic;

    // Tokens sold per round
    uint256 public seedSold;
    uint256 public privateSold;
    uint256 public publicSold;

    // Total IDTC purchased per address (all rounds combined)
    mapping(address => uint256) public tokensBought;

    // Per-address per-round totals (for max enforcement)
    mapping(address => uint256) public seedBought;
    mapping(address => uint256) public privateBought;
    mapping(address => uint256) public publicBought;

    // ─── Events ───────────────────────────────────────────────────────────────

    event RoundStarted(Round indexed round);
    event RoundStopped(Round indexed round);
    event TokensPurchased(
        address indexed buyer,
        Round indexed round,
        uint256 maticPaid,
        uint256 tokenAmount
    );
    event MaticRateUpdated(uint256 newUsdPerMatic);
    event Withdrawn(address indexed owner, uint256 amount);

    // ─── Constructor ──────────────────────────────────────────────────────────

    /**
     * @param _idtcToken     Address of deployed IDTC ERC-20 token.
     * @param _initialOwner  Owner/admin who controls rounds and withdrawals.
     * @param _usdPerMatic   USD price of 1 MATIC, 18 decimals.
     *                       Example: 1 MATIC = $0.87 → pass 870000000000000000 (0.87e18)
     *                       Example: 1 MATIC = $1.20 → pass 1200000000000000000 (1.20e18)
     */
    constructor(
        address _idtcToken,
        address _initialOwner,
        uint256 _usdPerMatic
    ) Ownable(_initialOwner) {
        require(_idtcToken != address(0), "Zero token address");
        require(_usdPerMatic > 0, "Invalid MATIC rate");
        idtcToken = IERC20(_idtcToken);
        usdPerMatic = _usdPerMatic;
        currentRound = Round.NONE;
        roundActive  = false;
    }

    // ─── Owner controls ───────────────────────────────────────────────────────

    /// @notice Start a sale round. Only owner.
    function startRound(Round _round) external onlyOwner {
        require(_round != Round.NONE, "Invalid round");
        currentRound = _round;
        roundActive  = true;
        emit RoundStarted(_round);
    }

    /// @notice Stop the active round. Only owner.
    function stopRound() external onlyOwner {
        require(roundActive, "No active round");
        roundActive = false;
        emit RoundStopped(currentRound);
    }

    /**
     * @notice Update MATIC price in USD. Call when MATIC price changes.
     * @param _usdPerMatic New USD price of 1 MATIC, 18 decimals.
     *                     e.g. MATIC = $0.90 → pass 900000000000000000
     */
    function setUsdPerMatic(uint256 _usdPerMatic) external onlyOwner {
        require(_usdPerMatic > 0, "Invalid rate");
        usdPerMatic = _usdPerMatic;
        emit MaticRateUpdated(_usdPerMatic);
    }

    // ─── Public purchase ──────────────────────────────────────────────────────

    /**
     * @notice Buy IDTC by sending MATIC. Tokens transferred immediately.
     *
     * @dev Formula explanation:
     *   USD value of MATIC sent = msg.value * usdPerMatic / 1e18
     *   Token price in USD      = priceCents / 100
     *   Tokens out              = USD value / Token price
     *                           = (msg.value * usdPerMatic / 1e18) / (priceCents / 100)
     *                           = (msg.value * usdPerMatic * 100) / (priceCents * 1e18)
     *
     *   Example: 1 MATIC at $0.87, Private round ($0.04):
     *     = (1e18 * 0.87e18 * 100) / (4 * 1e18)
     *     = 21.75e18 → 21.75 IDTC ✓
     *
     * Checks-Effects-Interactions: all state written before token transfer.
     */
    function buyTokens() external payable nonReentrant {
        require(roundActive,       "No active round");
        require(msg.value > 0,     "Send MATIC to buy");

        uint256 priceCents = _currentPriceCents();
        uint256 cap        = _currentCap();
        uint256 minAmt     = _currentMin();
        uint256 maxAmt     = _currentMax();

        // Calculate tokens out (integer arithmetic, rounds down — protects contract)
        uint256 tokensOut = (msg.value * usdPerMatic * 100) / (priceCents * 1e18);
        require(tokensOut > 0, "Insufficient MATIC for min 1 token");

        // Enforce per-purchase min
        require(tokensOut >= minAmt, "Below minimum purchase");

        // Enforce per-address max across this round
        uint256 alreadyBought = _addressRoundBought(msg.sender);
        require(alreadyBought + tokensOut <= maxAmt, "Exceeds per-address maximum");

        // Enforce round hard cap
        uint256 sold = _currentSold();
        require(sold + tokensOut <= cap, "Exceeds round cap");

        // ── Effects (all state before external call) ─────────────────────────
        _addSold(tokensOut);
        _addAddressBought(msg.sender, tokensOut);
        tokensBought[msg.sender] += tokensOut;

        // ── Interactions ─────────────────────────────────────────────────────
        require(
            idtcToken.transferFrom(owner(), msg.sender, tokensOut),
            "Token transfer failed"
        );

        emit TokensPurchased(msg.sender, currentRound, msg.value, tokensOut);
    }

    /// @notice Withdraw MATIC raised to owner wallet.
    function withdraw() external onlyOwner nonReentrant {
        uint256 balance = address(this).balance;
        require(balance > 0, "Nothing to withdraw");
        emit Withdrawn(msg.sender, balance);
        (bool ok, ) = payable(owner()).call{value: balance}("");
        require(ok, "Withdraw failed");
    }

    // ─── Views ────────────────────────────────────────────────────────────────

    /// @notice How many IDTC tokens you get for a given MATIC amount in active round.
    function tokensForMatic(uint256 maticAmount) external view returns (uint256) {
        if (!roundActive || maticAmount == 0) return 0;
        uint256 priceCents = _currentPriceCents();
        return (maticAmount * usdPerMatic * 100) / (priceCents * 1e18);
    }

    /// @notice Current round token price in MATIC wei per 1 IDTC.
    function currentPriceInMatic() external view returns (uint256) {
        if (!roundActive) return 0;
        uint256 priceCents = _currentPriceCents();
        // price_matic = (priceCents / 100) / usdPerMatic → in wei: priceCents * 1e18 / (100 * usdPerMatic)
        return (priceCents * 1e18) / (100 * usdPerMatic);
    }

    /// @notice Tokens remaining in the current round.
    function remainingInRound() external view returns (uint256) {
        if (!roundActive) return 0;
        return _currentCap() - _currentSold();
    }

    // ─── Internal helpers ─────────────────────────────────────────────────────

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

    function _currentMin() internal view returns (uint256) {
        if (currentRound == Round.SEED)    return SEED_MIN;
        if (currentRound == Round.PRIVATE) return PRIVATE_MIN;
        if (currentRound == Round.PUBLIC)  return PUBLIC_MIN;
        revert("No active round");
    }

    function _currentMax() internal view returns (uint256) {
        if (currentRound == Round.SEED)    return SEED_MAX;
        if (currentRound == Round.PRIVATE) return PRIVATE_MAX;
        if (currentRound == Round.PUBLIC)  return PUBLIC_MAX;
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

    function _addressRoundBought(address buyer) internal view returns (uint256) {
        if (currentRound == Round.SEED)    return seedBought[buyer];
        if (currentRound == Round.PRIVATE) return privateBought[buyer];
        if (currentRound == Round.PUBLIC)  return publicBought[buyer];
        revert("No active round");
    }

    function _addAddressBought(address buyer, uint256 amount) internal {
        if (currentRound == Round.SEED)         seedBought[buyer]    += amount;
        else if (currentRound == Round.PRIVATE) privateBought[buyer] += amount;
        else if (currentRound == Round.PUBLIC)  publicBought[buyer]  += amount;
    }

    /// @dev Reject direct MATIC transfers — must use buyTokens()
    receive() external payable {
        revert("Use buyTokens()");
    }
}
