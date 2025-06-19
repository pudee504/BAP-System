<link rel="stylesheet" href="style.css">
<style>
  .tabs {
    display: flex;
    margin-top: 20px;
  }
  .tab {
    padding: 10px 20px;
    background: #eee;
    margin-right: 5px;
    cursor: pointer;
    border-radius: 5px 5px 0 0;
  }
  .tab.active {
    background: #fff;
    font-weight: bold;
  }
  .tab-content {
    display: none;
    padding: 20px;
    background: #fff;
    border: 1px solid #ccc;
    border-top: none;
  }
  .tab-content.active {
    display: block;
  }

  .match-cell {
    padding: 0.5rem;
    line-height: 1.6;
  }

  .team-line {
    display: block;
    padding: 4px 0;
  }
</style>
